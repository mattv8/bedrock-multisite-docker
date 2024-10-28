-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS `DB_NAME`;

-- Ensure the user exists (this may fail if the user already exists)
CREATE USER IF NOT EXISTS 'DB_USER'@'%' IDENTIFIED BY 'DB_PASSWORD';

-- Grant all privileges on the specified database to the user
GRANT ALL PRIVILEGES ON `DB_NAME`.* TO 'DB_USER'@'%';

-- Apply the changes
FLUSH PRIVILEGES;

-- Multi-site installs, insert these rows after DB initialization
--INSERT INTO `ft_site` (domain, path) VALUES ('http://www.localhost:81', '/');
--INSERT INTO `ft_blogs` (site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id)
--VALUES (1, 'www.localhost:81', '/', NOW(), NOW(), 1, 0, 0, 0, 0, 0);