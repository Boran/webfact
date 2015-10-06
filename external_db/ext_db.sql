#
# Stored procedure to allow create user+database and grant access to it
# Useful on an external database (not within the container)
# Allow access from any client (assume a firewall restricts access)
#
# Original snippit: http://superuser.com/questions/424476/grant-mysql-user-ability-to-create-databases-and-only-allow-them-to-access-thos#424493
#
# Create and grant access to your webfact user
#   echo "create user webfact_create identified by 'ho4iaH4o'" | mysql mysql
# Install the procedures:
#  mysql mysql < external_db/ext_db.sql
# then
# add that user/pw to the settings in the webfact UI, admin/config/development/webfact.
#

DELIMITER //
DROP PROCEDURE IF EXISTS CreateAppDB//
CREATE PROCEDURE CreateAppDB(IN db_name VARCHAR(50), IN db_user VARCHAR(50), IN db_pw VARCHAR(50))
BEGIN
    # grant will do a create user if needed
    SET @s = CONCAT('GRANT ALL ON ', db_name, '.* TO ''', db_user, '''@''%''' , ' IDENTIFIED BY ''', db_pw, '''');
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    # this must be the second statement
    SET @s = CONCAT('CREATE DATABASE ', db_name);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END//

DROP PROCEDURE IF EXISTS DeleteAppDB//
CREATE PROCEDURE DeleteAppDB(IN db_name VARCHAR(50), IN db_user VARCHAR(50))
BEGIN
    #SET @s = CONCAT('DROP USER ', db_user, '''@''%''' );
    SET @s = CONCAT('DROP USER ', db_user);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

    # this must be the second statement
    SET @s = CONCAT('DROP DATABASE ', db_name);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END//


-- stored procedure rename_db: Rename a database my means of table copying.
-- Will clobber any existing database with the same name as the 'new' database name.
-- Only copies tables; stored procedures, views, triggers and other db objects are not copied.
-- Tomer Altman (taltman@ai.sri.com) http://stackoverflow.com/questions/67093/how-do-i-quickly-rename-a-mysql-database-change-schema-name
-- Webfactory: We call it RenameAppDB() rather than rename_db(). Adapted to rename user too.


delimiter //
DROP PROCEDURE IF EXISTS RenameAppDB;
CREATE PROCEDURE RenameAppDB(IN old_db VARCHAR(100), IN new_db VARCHAR(100), IN old_user VARCHAR(50), new_user VARCHAR(50))
BEGIN
    DECLARE current_table VARCHAR(100);
    DECLARE done INT DEFAULT 0;
    DECLARE old_tables CURSOR FOR select table_name from information_schema.tables where table_schema = old_db;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    # Delete and create the newdb, but delete not needed for webfactory?
    #SET @output = CONCAT('DROP SCHEMA IF EXISTS ', new_db, ';'); 
    #PREPARE stmt FROM @output;
    #EXECUTE stmt;
    SET @output = CONCAT('CREATE SCHEMA IF NOT EXISTS ', new_db, ';');
    PREPARE stmt FROM @output;
    EXECUTE stmt;

    # Copy old tables to newdb
    OPEN old_tables;
    REPEAT
        FETCH old_tables INTO current_table;
        IF NOT done THEN
        SET @output = CONCAT('alter table ', old_db, '.', current_table, ' rename ', new_db, '.', current_table, ';');
        PREPARE stmt FROM @output;
        EXECUTE stmt;

        END IF;
    UNTIL done END REPEAT;
    CLOSE old_tables;
    
    ## olddb
    SET @output = CONCAT('DROP SCHEMA IF EXISTS ', old_db, ';'); 
    PREPARE stmt FROM @output;
    EXECUTE stmt;

    ## rename user too
    SET @output = CONCAT('RENAME USER ', old_user, ' TO ' , new_user , ';'); 
    PREPARE stmt FROM @output;
    EXECUTE stmt;
END//
DELIMITER ;
grant execute on procedure CreateAppDB to 'webfact_create'@'%';
grant execute on procedure DeleteAppDB to 'webfact_create'@'%';
grant execute on procedure RenameAppDB to 'webfact_create'@'%';
show procedure status;
