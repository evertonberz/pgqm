<?php
/*
 * PGQM (PostgreSQL Query Monitor)
 * Autor: Everton Luís Berz <everton.berz@trt4.jus.br>
 *
 * Observação: a query fica truncada por causa do parâmetro track_activity_query_size do PostgreSQl. O padrão é 1024.
 *
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);
date_default_timezone_set("America/Sao_Paulo");

print("Iniciando PGQM (PostgreSQL Query Monitor)".PHP_EOL);

$ini = parse_ini_file("pgqm.ini", true);
$bdPostgresqlInfo = $ini["bdPostgresql"];
define("TOLERANCIA", $ini["geral"]["tolerancia"]);
define("NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA", $ini["geral"]["numeroDeQueriesAcimaDaTolerancia"]);
define("NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA_PARA_ALERTA", $ini["geral"]["numeroDeQueriesAcimaDaToleranciaParaAlerta"]);
define("PERIODO_DE_REPETICAO_NORMAL", $ini["geral"]["periodoDeRepeticaoNormal"]);
define("PERIODO_DE_REPETICAO_EM_CRISE", $ini["geral"]["periodoDeRepeticaoEmCrise"]);
define("DURACAO_DA_CRISE_PARA_ALERTA", $ini["geral"]["duracaoDaCriseParaAlerta"]);
define("TEMPO_DE_ESPERA_POR_ACAO", $ini["geral"]["tempoDeEsperaPorAcao"]);
define("DESTINATARIO_DO_EMAIL_DE_ALERTA", $ini["geral"]["destinatarioDoEmailDeAlerta"]);

print("Configuração".PHP_EOL);
print_r($ini["geral"]);
print(PHP_EOL);

$stringDeConexao = "host='$bdPostgresqlInfo[host]' port='$bdPostgresqlInfo[port]' dbname='$bdPostgresqlInfo[dbname]' user='$bdPostgresqlInfo[user]' password='$bdPostgresqlInfo[password]'";
$conexaoPg = pg_connect($stringDeConexao) or die("Erro conectando no servidor PostgreSQL: host=$bdPostgresqlInfo[host] port=$bdPostgresqlInfo[port] dbname=$bdPostgresqlInfo[dbname] user=$bdPostgresqlInfo[user]");

$conexaoSqlite = new SQLite3('pgqm.db');
//$conexaoSqlite->exec("DROP TABLE pgqm");
if ($conexaoSqlite->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='pgqm'") == null) {  
  $conexaoSqlite->exec("CREATE TABLE pgqm (mtimestamp integer, datname text, pid integer, usename text, client_addr text, query_start integer, 
    timediff integer, waiting integer, state text, query text, tolerancia integer, application_name text)");
}

$filtroStall = " and age(now(), query_start) > cast($1 as interval)";
$sqlMonitor = "select 
    datname, 
    pid, 
    client_addr, 
    usename, 
    extract(epoch from query_start) as query_start, 
    trunc(extract(epoch from age(now(), query_start))) as timediff, 
    waiting, 
    state,
    query,
    application_name     
	from pg_stat_activity 
	where   
   pid <> pg_backend_pid() and 
	 (state = 'active' or waiting = true)
   ###filtroStall###
	order by query_start";
$sqlMonitorFiltrado = str_replace("###filtroStall###", $filtroStall, $sqlMonitor);
$sqlMonitorSemFiltro = str_replace("###filtroStall###", "", $sqlMonitor);
$pSelFiltrado = pg_prepare($conexaoPg, "monitorFiltrado", $sqlMonitorFiltrado);
$pSelSemFiltro = pg_prepare($conexaoPg, "monitorSemFiltro", $sqlMonitorSemFiltro);

$pIns = $conexaoSqlite->prepare("INSERT INTO pgqm (mtimestamp, datname, pid, usename, client_addr, query_start, timediff, waiting, state, query, tolerancia, application_name) 
	VALUES (:mtimestamp, :datname, :pid, :usename, :client_addr, :query_start, :timediff, :waiting, :state, :query, :tolerancia, :application_name)");

$identificadorDoLoteDeColeta = time();
$identificadorDoLoteDaPrimeiraColetaDaCrise=$identificadorDoLoteDeColeta;
$timestampDaUltimaMensagemEnviada=null;

$periodoDeRepeticao=PERIODO_DE_REPETICAO_NORMAL;
while (true) {
  $resMonitorFiltrado = pg_execute($conexaoPg, "monitorFiltrado", array(TOLERANCIA." seconds"));
  $resMonitorSemFiltro = pg_execute($conexaoPg, "monitorSemFiltro", array());
  $registrosEncontrados = pg_num_rows($resMonitorFiltrado);
  $identificadorDoLoteDeColeta = time();
  if ($registrosEncontrados >= NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA) {    
    $periodoDeRepeticao=PERIODO_DE_REPETICAO_EM_CRISE;
    print(PHP_EOL."[".date("d-m-Y H:i:s", $identificadorDoLoteDeColeta)."] $registrosEncontrados queries lentas encontradas ($identificadorDoLoteDeColeta) ");    
  	while ($linha = pg_fetch_array($resMonitorSemFiltro)) {
      $pIns->bindValue(':mtimestamp', $identificadorDoLoteDeColeta, SQLITE3_INTEGER);
  		$pIns->bindValue(':datname', $linha["datname"], SQLITE3_TEXT);
  		$pIns->bindValue(':pid', $linha["pid"], SQLITE3_INTEGER);
  		$pIns->bindValue(':usename', $linha["usename"], SQLITE3_TEXT);
  		$pIns->bindValue(':client_addr', $linha["client_addr"], SQLITE3_TEXT);
  		$pIns->bindValue(':query_start', $linha["query_start"], SQLITE3_INTEGER);
  		$pIns->bindValue(':timediff', $linha["timediff"], SQLITE3_INTEGER);
      if ($linha["waiting"] == "t")
  		  $pIns->bindValue(':waiting', 1, SQLITE3_INTEGER);
      else
        $pIns->bindValue(':waiting', 0, SQLITE3_INTEGER);
      $pIns->bindValue(':state', $linha["state"], SQLITE3_TEXT);
      $pIns->bindValue(':query', $linha["query"], SQLITE3_TEXT);
      $pIns->bindValue(':tolerancia', TOLERANCIA, SQLITE3_INTEGER);
      $pIns->bindValue(':application_name', $linha["application_name"], SQLITE3_TEXT);
      $pIns->execute();
  	}

    $duracaoDaCrise= ($identificadorDoLoteDeColeta-$identificadorDoLoteDaPrimeiraColetaDaCrise);
    if ($registrosEncontrados >= NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA_PARA_ALERTA and 
        $duracaoDaCrise > DURACAO_DA_CRISE_PARA_ALERTA and
        (time()-$timestampDaUltimaMensagemEnviada >= TEMPO_DE_ESPERA_POR_ACAO or 
         $timestampDaUltimaMensagemEnviada == null)) {
      print(PHP_EOL);
      $mensagem = "PGQM (PostgreSQL Query Monitor)".PHP_EOL.
                   PHP_EOL.
                  "Alerta às ". date("d-m-Y H:i:s", time()).PHP_EOL.
                  "O banco de dados ".$bdPostgresqlInfo["host"]." já está há mais de ".DURACAO_DA_CRISE_PARA_ALERTA." segundos com queries lentas ativas. ".PHP_EOL.
                  PHP_EOL.
                  "Número de queries lentas: $registrosEncontrados".PHP_EOL.
                  "ID da primeira coleta: $identificadorDoLoteDaPrimeiraColetaDaCrise (".date("d-m-Y H:i:s", $identificadorDoLoteDaPrimeiraColetaDaCrise).")".PHP_EOL.
                  "ID da última coleta: $identificadorDoLoteDeColeta (".date("d-m-Y H:i:s", $identificadorDoLoteDeColeta).")".PHP_EOL.
                  "Duração da crise: {$duracaoDaCrise}s (por quanto tempo as queries lentas permanecem ativas)".PHP_EOL.
                  PHP_EOL.
                  "Configuração atual:".PHP_EOL.
                  "Tolerância: ".TOLERANCIA."s (queries com duração maior que essa são consideradas lentas)".PHP_EOL.
                  "Número de queries lentas: ".NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA.PHP_EOL.
                  "Número de queries lentas para emitir alerta por e-mail: ".NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA_PARA_ALERTA.PHP_EOL.
                  "Duração da crise: ".DURACAO_DA_CRISE_PARA_ALERTA."s (quanto tempo permanece em crise para daí enviar este alerta)".PHP_EOL.
                  PHP_EOL.
                  "Esta mensagem não será mais enviada nos próximos ".TEMPO_DE_ESPERA_POR_ACAO." segundos.".PHP_EOL.
                  PHP_EOL.
                  "Se você acha que esta situação irá pendurar a aplicação, execute os comandos abaixo imediatamente.".PHP_EOL.
                  "Estes são os pid's das queries mais lentas:".PHP_EOL;        
      $sql = "select query, timediff, 'select pg_terminate_backend(' || pid || ');' as kill 
              from pgqm 
              where mtimestamp = $identificadorDoLoteDeColeta 
              and timediff > ".TOLERANCIA." 
              order by timediff desc";
      $pSelMsg = $conexaoSqlite->query($sql);
      
      while ($linhaDetalhe = $pSelMsg->fetchArray()) {
        $comentario = strtok($linhaDetalhe["query"], " ")." executando há mais de $linhaDetalhe[timediff] segundos";
        $mensagem .= $linhaDetalhe["kill"]."  -- ".$comentario.PHP_EOL;
      }
      $mensagem .= PHP_EOL."FIM!";

      $timestampDaUltimaMensagemEnviada=time();                    
      print($mensagem.PHP_EOL.PHP_EOL.PHP_EOL);
      mail(DESTINATARIO_DO_EMAIL_DE_ALERTA, "PGQM Alerta", $mensagem);
    }

  } else {    
    print(".");
    $periodoDeRepeticao=PERIODO_DE_REPETICAO_NORMAL;
    $identificadorDoLoteDaPrimeiraColetaDaCrise=$identificadorDoLoteDeColeta;
  }

  sleep($periodoDeRepeticao);
}
?>
