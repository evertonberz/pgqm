<?php
/*
 * PGQM (PostgreSQL Query Monitor)
 * Autor: Everton Luís Berz <everton.berz@gmail.com>
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


$listaDeServidoresParaAcao = @explode(",", $ini["acao"]["servidores"]);
define("COMANDO_ACAO", $ini["acao"]["comando"]);

print("Configuração".PHP_EOL);
print_r($ini["geral"]);
print_r($ini["acao"]);
print(PHP_EOL);

$conexaoSqlite = new SQLite3('pgqm.db');
//$conexaoSqlite->exec("DROP TABLE pgqm");
if ($conexaoSqlite->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='pgqm'") == null) {  
  $conexaoSqlite->exec("CREATE TABLE pgqm (mtimestamp integer, datname text, pid integer, usename text, client_addr text, query_start integer, 
    timediff integer, waiting integer, state text, query text, tolerancia integer, application_name text)");
}

while (true) {  // loop da conexao ao postgres (para caso de falha na conexao)
  print("Conectando no PostgreSQL ($bdPostgresqlInfo[host])...\n");
  $stringDeConexao = "host='$bdPostgresqlInfo[host]' port='$bdPostgresqlInfo[port]' dbname='$bdPostgresqlInfo[dbname]' user='$bdPostgresqlInfo[user]' password='$bdPostgresqlInfo[password]'";
  $conexaoPg = @pg_connect($stringDeConexao);

  if (!$conexaoPg) {
    print("Erro conectando no servidor PostgreSQL: host=$bdPostgresqlInfo[host] port=$bdPostgresqlInfo[port] dbname=$bdPostgresqlInfo[dbname] user=$bdPostgresqlInfo[user]".PHP_EOL);
    sleep(3);
    continue; 
  }


  $filtroStall = "and (state = 'active' or waiting = true) and age(now(), query_start) > cast($1 as interval)";
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
     pid <> pg_backend_pid() 
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

    $stat = pg_connection_status($conexaoPg);
    if ($stat !== 0) {
      print("Conexao falhou, fechando conexao...\n");
      pg_close($conexaoPg);
      sleep(10);
      break; // sai do loop interno das queries e volta pro loop das tentativas de conexao
    }

    $resMonitorFiltrado = pg_execute($conexaoPg, "monitorFiltrado", array(TOLERANCIA." seconds"));

    $resMonitorSemFiltro = pg_execute($conexaoPg, "monitorSemFiltro", array());
    $registrosEncontrados = pg_num_rows($resMonitorFiltrado);
    $identificadorDoLoteDeColeta = time();
    if ($registrosEncontrados >= NUMERO_DE_QUERIES_ACIMA_DA_TOLERANCIA) {
      $periodoDeRepeticao=PERIODO_DE_REPETICAO_EM_CRISE;
      print(PHP_EOL."[".date("d-m-Y H:i:s", $identificadorDoLoteDeColeta)."] $registrosEncontrados queries lentas encontradas ($identificadorDoLoteDeColeta). Guardando snapshot (".pg_num_rows($resMonitorSemFiltro)." registros)... ");    

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
          $duracaoDaCrise > DURACAO_DA_CRISE_PARA_ALERTA) {
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
                    "Estes são as queries mais lentas:".PHP_EOL.PHP_EOL.
        "PID | Application name | Duração | Query".PHP_EOL;
        $sql = "select query, timediff, pid, application_name
                from pgqm 
                where mtimestamp = $identificadorDoLoteDeColeta 
                and timediff > ".TOLERANCIA." and
                  (state = 'active' or state = 'idle in transaction' or waiting = 1) 
                order by timediff desc";
        $pSelMsg = $conexaoSqlite->query($sql);
        
        while ($linhaDetalhe = $pSelMsg->fetchArray()) {
          $mensagem .= "$linhaDetalhe[pid] | $linhaDetalhe[application_name] | $linhaDetalhe[timediff] | ".substr($linhaDetalhe["query"], 0, 120).PHP_EOL;
        }
        $mensagem .= PHP_EOL."FIM!";
        print($mensagem.PHP_EOL.PHP_EOL.PHP_EOL);

        if (time()-$timestampDaUltimaMensagemEnviada >= TEMPO_DE_ESPERA_POR_ACAO or 
           $timestampDaUltimaMensagemEnviada == null) {

          // Only send emails between 6 am and 23 am
          if (date('G') > 5) {
            mail(DESTINATARIO_DO_EMAIL_DE_ALERTA, "PGQM Alerta", $mensagem);
            $timestampDaUltimaMensagemEnviada=time();
          
            // trigger
            foreach ($listaDeServidoresParaAcao as $servidorRemotoAcao) {
              print("Enviando ação para $servidorRemotoAcao...".PHP_EOL);
              
              $comandoAcao = COMANDO_ACAO;
              $comandoAcao = str_replace("###servidor###", $servidorRemotoAcao, $comandoAcao);
              $comandoAcao = str_replace("###identificador###", $identificadorDoLoteDeColeta, $comandoAcao);
              print("Comando: $comandoAcao".PHP_EOL);

              //$saida = exec($comandoAcao);
            }
            print("Acoes enviadas!".PHP_EOL.PHP_EOL);
            // fim acao

          }

        }

      }

    } else {    
      print(".");
      $periodoDeRepeticao=PERIODO_DE_REPETICAO_NORMAL;
      $identificadorDoLoteDaPrimeiraColetaDaCrise=$identificadorDoLoteDeColeta;
    }
    sleep($periodoDeRepeticao);
  } // loop das consultas
  
} // loop da conexao ao postgres (para caso de falha na conexao)
?>
