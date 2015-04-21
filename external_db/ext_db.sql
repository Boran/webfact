#
# Stored procedure to allow create user+database and grant access to it
# Useful on an external database (not within the container)
# Allow access from any client (assume a firewall restricts access)
#
# Original snippit: http://superuser.com/questions/424476/grant-mysql-user-ability-to-create-databases-and-only-allow-them-to-access-thos#424493
#
# Install: first load the proc:
#  mysql mysql < scripts/ext_db.sql
# Then create and grant access to your webfact user
#   echo "create user webfact_create identified by 'ho4iaH4o'" | mysql mysql
#   echo "grant execute on procedure CreateAppDB to 'webfact_create'@'%'" | mysql mysql
# then
# add that user/pw to the settings in the webfact UI, admin/config/development/webfact.
#

DELIMITER //
DROP PROCEDURE IF EXISTS CreateAppDB//
CREATE PROCEDURE CreateAppDB(IN db_name VARCHAR(50), IN db_user VARCHAR(50), IN db_pw VARCHAR(50))
BEGIN
    #SET @s = CONCAT('CREATE USER ', db_user, ' IDENTIFIED BY ''', db_pw, '''');
    #PREPARE stmt FROM @s;
    #EXECUTE stmt;
    #DEALLOCATE PREPARE stmt;
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
DELIMITER ;
grant execute on procedure CreateAppDB to 'webfact_create'@'%';

