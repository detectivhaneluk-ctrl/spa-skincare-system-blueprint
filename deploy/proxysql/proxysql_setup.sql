-- ProxySQL backend setup SQL
-- Run via: mysql -u $PROXYSQL_ADMIN_USER -p$PROXYSQL_ADMIN_PASSWORD -h 127.0.0.1 -P 6032 < proxysql_setup.sql
-- Substitute @VARS below with actual values before running.

-- Set variables (replace these with real values)
SET @primary_host = '10.0.0.1';
SET @primary_port = 3306;
SET @replica_host = '10.0.0.2';
SET @replica_port = 3306;
SET @app_user = 'spa_app';
SET @app_password = 'CHANGE_ME';
SET @database = 'spa_production';

-- Clear existing config
DELETE FROM mysql_servers;
DELETE FROM mysql_users;
DELETE FROM mysql_query_rules;
DELETE FROM mysql_replication_hostgroups;

-- Add backend servers
INSERT INTO mysql_servers (hostgroup_id, hostname, port, max_connections, max_replication_lag, comment)
VALUES
  (0, @primary_host, @primary_port, 100, 0, 'primary writer'),
  (1, @replica_host, @replica_port, 200, 10, 'read replica');

-- Add application user
INSERT INTO mysql_users (username, password, default_hostgroup, max_connections, default_schema, active, transaction_persistent)
VALUES (@app_user, @app_password, 0, 1000, @database, 1, 1);

-- Query routing rules
INSERT INTO mysql_query_rules (rule_id, active, match_pattern, destination_hostgroup, apply, comment)
VALUES
  (1, 1, '^SELECT .* FOR UPDATE', 0, 1, 'locking SELECT to primary'),
  (2, 1, '^(BEGIN|START TRANSACTION)', 0, 1, 'explicit transactions to primary'),
  (3, 1, '^SELECT', 1, 1, 'non-locking SELECT to replica');

-- Replication hostgroup
INSERT INTO mysql_replication_hostgroups (writer_hostgroup, reader_hostgroup, comment)
VALUES (0, 1, 'ollira primary/replica pair');

-- Load to runtime and persist
LOAD MYSQL SERVERS TO RUNTIME;
SAVE MYSQL SERVERS TO DISK;
LOAD MYSQL USERS TO RUNTIME;
SAVE MYSQL USERS TO DISK;
LOAD MYSQL QUERY RULES TO RUNTIME;
SAVE MYSQL QUERY RULES TO DISK;

-- Verify
SELECT hostgroup_id, hostname, port, status FROM mysql_servers;
SELECT username, default_hostgroup FROM mysql_users;
SELECT rule_id, match_pattern, destination_hostgroup FROM mysql_query_rules;
