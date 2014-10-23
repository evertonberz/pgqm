select 'select pg_terminate_backend(' || p.pid || ');'
from pgqm p
where p.waiting = 0  
and p.mtimestamp >= 1412795719
and timediff > 8
 -- and client_addr in ('10.4.20.207', '10.4.20.160', '10.4.20.161', '10.4.20.208', '10.4.20.211')
-- and p.pid not in (29573, 43249)
order by p.timediff desc;

