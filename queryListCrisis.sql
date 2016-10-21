select datetime(p.mtimestamp, 'unixepoch') as coleta, p.datname as database, p.pid, p.usename as username, 
p.client_addr as ip_servidor_jboss, datetime(p.query_start, 'unixepoch') as timestamp_inicio_query, p.timediff as duracao, p.waiting as em_espera_lock, 
p.state, p.query, p.threshold as threshold_pgqm, p.application_name
from pgqm p
-- where datetime(p.query_start, 'unixepoch') like '2015-08-14%'
where p.mtimestamp = 1443626107
 -- and p.query like '%vacuum%'
order by p.mtimestamp, p.timediff desc;

