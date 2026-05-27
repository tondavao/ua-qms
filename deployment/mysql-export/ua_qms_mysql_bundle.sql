-- UA QMS MySQL deployment bundle
-- Purpose:
--   Run the current project schema/migrations in MySQL using a single entry file.
-- Notes:
--   1. This bundle is for MySQL/MariaDB, not Supabase/Postgres.
--   2. Run this from the project root in the MySQL client:
--        mysql -u root -p < deployment/mysql-export/ua_qms_mysql_bundle.sql
--   3. If your MySQL client does not allow SOURCE from redirected input,
--      open the mysql shell first, then run:
--        SOURCE deployment/mysql-export/ua_qms_mysql_bundle.sql;

SOURCE database/ua_qms_database.sql;
SOURCE database/PHASE1_ESSENTIAL_FIXES.sql;
SOURCE database/ADD_AY_SEM_TO_DOCUMENTS.sql;
SOURCE database/ADD_NOTIFICATION_ROUTING.sql;
SOURCE database/DYNAMIC_SETTINGS_SYSTEM.sql;
SOURCE database/REPOSITORY_ENHANCEMENTS.sql;
