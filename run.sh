#!/bin/bash
/usr/bin/php /opt/pgqm/pgqm.php  > /var/log/pgqm/`date +"%Y%m%d-%H%M%S"`.log 2>&1
