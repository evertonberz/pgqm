PGQM - PostgreSQL Query Monitor
====

Query monitoring tool for PostgreSQL. It sends alert mails in case the query duration exceeds some threshold.

This script monitors active slow queries based on parameters such as query duration and  slow queries count. For instance, PGQM is useful if you want to list which queries were active in the database, at the time that 5 slow queries were running for more than 8 seconds.

The script stores a pg_stat_activity snapshot in a SQLite database.

## Installation

### Docker

1. Save pgqm.ini-dist to /pgqm-local-folder/pgqm.ini
2. Configure pgqm.ini
3. Run: 
```bash
docker run -v /pgqm-local-folder/:/pgqm-vol/ -it pgqm /pgqm-vol/pgqm.ini
```

### From source

Dependencies:
- php-cli
- php-common
- php-pgsql
- php-sqlite3

Tested in PHP 5 and PHP 7.

Instructions:
1. Rename pgqm.ini-dist to pgqm.ini
2. Configure pgqm.ini
3. Configure run.sh
4. Run run.sh

## Config file (pgqm.ini) explained

Some concepts:
- Slow query: a query that exceeds the specified time threshold (the duration is defined in the "threshold" directive)
- Crisis: slow queries running for a long time in the database
- Alert mail: condition 1 *and* condition 2 must be true (see below)

Directives:
- _queryCountAboveThreshold_: if slow query count reach this limit, then PGQM shows a warning and stores a pg_stat_activty snapshot
- _queryCountAboveThresholdAlert_: if slow query count reach this limit, then condition 1 for the alert mail is activated
- _crisisDurationForAlert_: if there are active slow queries for more than [crisisDurationForAlert] seconds, then condition 2 for the alert mail is activated
- _defaultIterationRange_: fetch slow queries from pg_stat_activity every [defaultIterationRange] seconds
- _iterationRangeWhileCrisis_: if the crisis flag is active, then fetch slow queries from pg_stat_activity every [iterationRangeWhileCrisis] seconds
- _waitTimeForAction_: the alert mail will be suspended for [waitTimeForAction] after the first alert (nobody wants spam)
