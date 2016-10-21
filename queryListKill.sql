select 'select pg_terminate_backend(' || p.pid || ');'
from pgqm p
where p.waiting = 0  
and p.mtimestamp >= 1412795719
and timediff > 8
-- and p.pid not in (29573, 43249)
order by p.timediff desc;

