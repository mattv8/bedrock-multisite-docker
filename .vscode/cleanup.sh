#!/bin/bash
# -----------------------------------------------------------------------------
# Clean-up Script: Remove files from the target directory that no longer exist
# in the Git-tracked source repository and are not excluded by .gitignore.
#
# This script:
#   1. Normalizes the source and target directories.
#   2. Lists Git-tracked files (relative to the source directory), prefixing them
#      with "./" so that they match candidate paths.
#   3. Uses find, sed, sort, and comm to compute candidate files (files in target
#      that are not tracked by Git) in one batch.
#   4. Uses a batch git check-ignore to determine which candidate files are
#      ignored (and therefore should be preserved). If the --parallel flag is
#      provided and GNU parallel is available, the candidate list is split into
#      chunks of a user-specified (or default) size and processed concurrently.
#      The chunk size is checked to be within 5%-25% of the total candidate count.
#   5. Computes the deletion list (candidates not ignored) in batch.
#   6. Deletes (or lists, in dry-run mode) the deletion list:
#      - With a progress loop if --progress is specified.
#      - Otherwise, in one batch via xargs.
#
# Usage:
#   bash cleanup.sh [--dry-run] [-v] [--progress] [--parallel[=<chunk_size>]] <src_dir> <target_dir>
#
# Example (from the source repo root):
#   bash cleanup.sh --dry-run -v --progress --parallel=10000 ./relative/path/ ~/git/controlled/path/
# -----------------------------------------------------------------------------

# Default settings.
dry_run=0
verbose=0
progress_flag=0
parallel_flag=0
chunk_size=1000  # default chunk size if --parallel is given without value

# Process flags.
while [[ "$1" == -* ]]; do
  case "$1" in
    --dry-run)
      dry_run=1
      echo "Running in dry-run mode. No files will be deleted."
      shift
      ;;
    -v)
      verbose=1
      shift
      ;;
    --progress)
      progress_flag=1
      shift
      ;;
    --parallel=*)
      parallel_flag=1
      chunk_size="${1#*=}"
      shift
      ;;
    --parallel)
      parallel_flag=1
      shift
      ;;
    *)
      echo "Unknown option: $1"
      echo "Usage: $0 [--dry-run] [-v|-vv] [--progress] [--parallel[=<chunk_size>]] <src_dir> <target_dir>"
      exit 1
      ;;
  esac
done

# Ensure exactly two arguments remain.
if [ "$#" -ne 2 ]; then
  echo "Usage: $0 [--dry-run] [-v|-vv] [--progress] [--parallel[=<chunk_size>]] <src_dir> <target_dir>"
  exit 1
fi

src_dir="$1"
target_dir="$2"

# Normalize src_dir: expand tilde and remove trailing slash.
src_dir=$(eval echo "$src_dir")
src_dir="${src_dir%/}"

# Normalize target_dir: expand tilde and remove trailing slash.
target_dir=$(eval echo "$target_dir")
target_dir="${target_dir%/}"

# Define a debug logging function.
debug_log() {
  if [ "$verbose" -ge 1 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') DEBUG: $*"
  fi
}

debug_log "Source directory: ${src_dir}"
debug_log "Target directory: ${target_dir}"

# Verify that the source directory is a Git repository.
if [ ! -d "${src_dir}/.git" ]; then
  echo "Source directory is not a Git repository: ${src_dir}"
  exit 1
fi

# Create temporary files.
tracked_list=$(mktemp)
candidate_list=$(mktemp)
ignored_list=$(mktemp)
deletion_list=$(mktemp)

# 1. List all tracked files (relative paths) from the source repository,
#    prefixing each line with "./" so that they match candidate paths.
git -C "$src_dir" ls-files --full-name | sed 's/^/.\//' > "$tracked_list"
tracked_count=$(wc -l < "$tracked_list")
debug_log "Found ${tracked_count} tracked files."

# 2. Build a candidate file list from the target directory using batch processing.
#    Steps:
#      a. Use find to list all files under target_dir.
#      b. Use sed to remove the target_dir prefix and add "./" at the beginning.
#      c. Sort the candidate list and the tracked list.
#      d. Use comm to compute the difference (files in target but not tracked).
all_candidates=$(mktemp)
find "$target_dir" -type f | sed "s#^${target_dir}/#./#" > "$all_candidates"
sorted_candidates=$(mktemp)
sort "$all_candidates" > "$sorted_candidates"
rm "$all_candidates"
sorted_tracked=$(mktemp)
sort "$tracked_list" > "$sorted_tracked"
comm -23 "$sorted_candidates" "$sorted_tracked" > "$candidate_list"
rm "$sorted_candidates" "$sorted_tracked"
candidate_count=$(wc -l < "$candidate_list")
debug_log "Found ${candidate_count} candidate files (in target but not tracked by Git)."

# 3. Batch check candidate files against .gitignore.
if [ $parallel_flag -eq 1 ]; then
  if command -v parallel >/dev/null 2>&1; then
    # Before splitting, check and adjust the chunk size.
    # Compute minimum and maximum allowed chunk sizes: 5% and 25% of candidate_count.
    min_allowed=$(awk "BEGIN {printf \"%d\", $candidate_count * 0.05}")
    max_allowed=$(awk "BEGIN {printf \"%d\", $candidate_count * 0.25}")
    if [ "$chunk_size" -lt "$min_allowed" ]; then
      debug_log "Provided chunk size ($chunk_size) is too small (<5% of total candidates). Adjusting to $min_allowed."
      chunk_size=$min_allowed
    elif [ "$chunk_size" -gt "$max_allowed" ]; then
      debug_log "Provided chunk size ($chunk_size) is too large (>25% of total candidates). Adjusting to $max_allowed."
      chunk_size=$max_allowed
    else
      debug_log "Using provided chunk size: $chunk_size"
    fi

    # Create a temporary directory for chunk files.
    chunk_dir=$(mktemp -d)
    # Split candidate_list into chunks of $chunk_size lines each.
    split -l "$chunk_size" "$candidate_list" "$chunk_dir/candidate_chunk_"
    # Process each chunk in parallel (using 4 concurrent jobs by default).
    parallel -j 4 "git -C '$src_dir' check-ignore --stdin < {}" ::: "$chunk_dir"/* > "$ignored_list"
    rm -r "$chunk_dir"
  else
    echo "Warning: GNU parallel not found." >&2
    echo "To install GNU parallel, try one of the following commands:" >&2
    echo "  Debian/Ubuntu: sudo apt-get update && sudo apt-get install parallel" >&2
    echo "  Fedora: sudo dnf install parallel" >&2
    echo "  CentOS/RHEL: sudo yum install epel-release && sudo yum install parallel" >&2
    echo "  Arch Linux: sudo pacman -S parallel" >&2
    echo "  macOS (Homebrew): brew install parallel" >&2
    echo "Falling back to sequential git check-ignore." >&2
    git -C "$src_dir" check-ignore --stdin < "$candidate_list" > "$ignored_list"
  fi
else
  git -C "$src_dir" check-ignore --stdin < "$candidate_list" > "$ignored_list"
fi
ignored_count=$(wc -l < "$ignored_list")
debug_log "Found ${ignored_count} ignored candidate files."

# 4. Compute the deletion list: candidates not ignored.
sort "$candidate_list" > "$candidate_list.sorted"
sort "$ignored_list" > "$ignored_list.sorted"
comm -23 "$candidate_list.sorted" "$ignored_list.sorted" > "$deletion_list"
rm "$candidate_list.sorted" "$ignored_list.sorted"
deletion_count=$(wc -l < "$deletion_list")
debug_log "${deletion_count} file(s) will be deleted."

# 5. Delete (or list) the deletion list.
if [ "$dry_run" -eq 1 ]; then
  if [ $progress_flag -eq 1 ]; then
    total=$(wc -l < "$deletion_list")
    current=0
    while IFS= read -r file; do
      ((current++))
      echo -ne "Deletion progress: ${current}/${total}\r"
      echo "Would delete: ${file#./}"
    done < "$deletion_list"
    echo ""
  else
    sed 's/^\.//' "$deletion_list" | while read -r file; do
    echo "Would delete: $file"
    done
  fi
else
  if [ $progress_flag -eq 1 ]; then
    total=$(wc -l < "$deletion_list")
    current=0
    while IFS= read -r file; do
      ((current++))
      echo -ne "Deletion progress: ${current}/${total}\r"
      rel_file="${file#./}"
      rm -f "$target_dir/$rel_file"
    done < "$deletion_list"
    echo ""
  else
    sed 's/^\.//' "$deletion_list" | xargs -d '\n' -r -I {} rm -f "$target_dir/{}"
  fi
fi

# Clean up temporary files.
rm "$tracked_list" "$candidate_list" "$ignored_list" "$deletion_list"
