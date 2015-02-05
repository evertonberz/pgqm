select datetime(p.mtimestamp, 'unixepoch') as coleta, p.datname as database, p.pid, p.usename as username, 
p.client_addr as ip_servidor_jboss, datetime(p.query_start, 'unixepoch') as timestamp_inicio_query, p.timediff as duracao, p.waiting as em_espera_lock, 
p.state, p.query, p.tolerancia as tolerancia_pgqm
from pgqm p
where p.mtimestamp =  1422291612
 -- and p.query like '%vacuum%'
order by p.mtimestamp desc, p.timediff desc;

