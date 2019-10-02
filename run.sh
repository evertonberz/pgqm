#!/bin/bash
php pgqm.php pgqm.ini > /var/log/pgqm/`date +"%Y%m%d-%H%M%S"`.log 2>&1