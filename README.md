pgqm
====

PostgreSQL Query Monitor

Licença: GPL v2.0

Query monitoring script for PostgreSQL. It sends alert mails in case the query duration exceeds some threshold.

This script monitors active slow queries based on the INI file parameters.
You can set the query duration and the slow queries count threshold.
For instance, I want to list which queries were active in the database, at the time that 5 slow queries were running for more than 8 seconds.

The script stores a pg_stat_activity snapshot in a SQLite database.

Dependences:
- php-cli
- php-common
- php-pgsql
- php-sqlite3

Tested in PHP 5 and PHP 7.

Instructions:
- rename pgqm.ini-dist to pgqm.ini
- configure pgqm.ini
- configure run.sh
- run run.sh

====
About the INI file

Some concepts:
- Slow query: a query that exceeds the specified time threshold (the duration is defined in the "threshold" directive)
- Crisis: slow queries running for a long time in the database
- Alert mail: condition 1 *and* condition 2 must be true (see below)

Directives:
- _queryCountAboveThreshold_: if slow query count reach this limit, then PGQM shows a warning and stores a pg_stat_activty snapshot
- _queryCountAboveThresholdAlert_: if slow query count reach this limit, then condition 1 for the alert mail is activated
- _crisisDurationForAlert_: if there are active slow queries for more than <crisisDurationForAlert> seconds, then condition 2 for the alert mail is activated
- _defaultIterationRange_: fetch slow queries in pg_stat_activity every <defaultIterationRange> seconds
- _iterationRangeWhileCrisis_: if the crisis flag is active, fetch slow queries in pg_stat_activity every <iterationRangeWhileCrisis> seconds
- _waitTimeForAction_: the alert mail will be suspended for <waitTimeForAction> after the first alert (nobody wants spam)
