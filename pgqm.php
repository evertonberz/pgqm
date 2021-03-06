<?php
/*
 * PGQM - PostgreSQL Query Monitor
 * Author: Everton Luís Berz <everton.berz@gmail.com>
 *
 * Query text could be truncated due to track_activity_query_size parameter from PostgreSQL. Default is 1024.
 *
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);
date_default_timezone_set("America/Sao_Paulo");

include('PHPMailer/PHPMailer.php');
include('PHPMailer/SMTP.php');
include('PHPMailer/Exception.php');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

define("VERSION", "1.0");
print("Starting PGQM (PostgreSQL Query Monitor) - Version ".VERSION.PHP_EOL);

if (!isset($argv[1])) {
  die("Usage: php pgqm.php path_to_ini_file".PHP_EOL);
}
$ini = parse_ini_file($argv[1], true) or die("Invalid ini file: $argv[1]");
$infoPostgresqlDb = $ini["postgresqlDb"];
define("QUERY_DURATION_THRESHOLD", $ini["general"]["queryDurationThreshold"]);
define("QUERY_COUNT_ABOVE_THRESHOLD", $ini["general"]["queryCountAboveThreshold"]);
define("QUERY_COUNT_ABOVE_THRESHOLD_ALERT", $ini["general"]["queryCountAboveThresholdAlert"]);
define("DEFAULT_ITERATION_RANGE", $ini["general"]["defaultIterationRange"]);
define("ITERATION_RANGE_WHILE_CRISIS", $ini["general"]["iterationRangeWhileCrisis"]);
define("CRISIS_DURATION_FOR_ALERT", $ini["general"]["crisisDurationForAlert"]);
define("WAIT_TIME_FOR_ACTION", $ini["general"]["waitTimeForAction"]);
define("ALERT_MAIL_RECIPIENT", $ini["general"]["alertMailRecipient"]);
define("ALERT_MAIL_SENDER", $ini["general"]["alertMailSender"]);
define("SQLITE_DB_PATH", $ini["general"]["sqliteDbPath"]);
define("SQLITE_DB_MAX_SIZE", $ini["general"]["sqliteDbMaxSize"]);
define("TAR_PATH", $ini["general"]["tarPath"]);
define("SMTP_HOST", $ini["general"]["smtpHost"]);
define("SMTP_PORT", $ini["general"]["smtpPort"]);

$triggerServerList = @explode(",", $ini["trigger"]["servers"]);
define("TRIGGER_COMMAND", $ini["trigger"]["command"]);

print("Config:".PHP_EOL);
print_r($ini["general"]);
print_r($ini["trigger"]);
print(PHP_EOL);

global $sqliteConnection;
global $pInsertSqlite;
connect_sqlite_database();

while (true) {  // postgresql connect loop (for reconnections)
  print("Connecting to PostgreSQL ($infoPostgresqlDb[host])...\n");
  $connectionString = "host='$infoPostgresqlDb[host]' port='$infoPostgresqlDb[port]' dbname='$infoPostgresqlDb[dbname]' user='$infoPostgresqlDb[user]' password='$infoPostgresqlDb[password]'";
  $pgConnection = @pg_connect($connectionString);

  if (!$pgConnection) {
    print("Error while connectiong to PostgreSQL database: host=$infoPostgresqlDb[host] port=$infoPostgresqlDb[port] dbname=$infoPostgresqlDb[dbname] user=$infoPostgresqlDb[user]".PHP_EOL);
    sleep(3);
    continue;
  }

  $pgVersion = get_postgresql_server_version($pgConnection);
  $pgMajorVersion = (int)substr($pgVersion, 0, strpos($pgVersion, "."));
  if (substr($pgVersion, 0, 3) == "9.6" or $pgMajorVersion >= 10) {
    $sqlWaitEvent = "wait_event";
    $sqlWhereWaitEvent = "(wait_event is not null and wait_event <> 'ClientRead')";
  } else {
    $sqlWaitEvent = "waiting as wait_event";
    $sqlWhereWaitEvent = "waiting = true";
  }

  $stallFilter = "and (state = 'active' or $sqlWhereWaitEvent) and age(now(), query_start) > cast($1 as interval)";
  $sqlMonitor = "select
      datname,
      pid,
      client_addr,
      usename,
      extract(epoch from query_start) as query_start,
      trunc(extract(epoch from age(now(), query_start))) as timediff,
      $sqlWaitEvent,
      state,
      query,
      application_name
    from pg_stat_activity
    where
     pid <> pg_backend_pid()
     ###stallFilter###
     order by query_start";
  $sqlMonitorFiltered = str_replace("###stallFilter###", $stallFilter, $sqlMonitor);
  $sqlMonitorNoFilter = str_replace("###stallFilter###", "", $sqlMonitor);
  $pSelFiltered = pg_prepare($pgConnection, "monitorFiltered", $sqlMonitorFiltered);
  $pSelNoFilter = pg_prepare($pgConnection, "monitorNoFilter", $sqlMonitorNoFilter);
  if (!$pSelFiltered or !$pSelNoFilter) {
    die("Error while preparing pg_stat_activity queries!".PHP_EOL);
  }

  $batchId = time();
  $batchIdFromFirstDetectionOfTheDefect=$batchId;
  $lastSentMessageTimestamp=null;

  $iteration = 0;
  $iterationRange = DEFAULT_ITERATION_RANGE;
  while (true) {

    $stat = pg_connection_status($pgConnection);
    if ($stat !== 0) {
      print("Connection failed, closing connection...\n");
      pg_close($pgConnection);
      sleep(10);
      break; // exit internal loop and return to postgresql connection loop
    }

    $resMonitorFiltered = pg_execute($pgConnection, "monitorFiltered", array(QUERY_DURATION_THRESHOLD." seconds"));

    $resMonitorNoFilter = pg_execute($pgConnection, "monitorNoFilter", array());
    $recordCount = pg_num_rows($resMonitorFiltered);
    $batchId = time();
    if ($recordCount >= QUERY_COUNT_ABOVE_THRESHOLD) {
      $iterationRange=ITERATION_RANGE_WHILE_CRISIS;
      print(PHP_EOL."[".date("d-m-Y H:i:s", $batchId)."] $recordCount queries lentas encontradas ($batchId). Guardando snapshot (".pg_num_rows($resMonitorNoFilter)." registros)... ");

      while ($line = pg_fetch_array($resMonitorNoFilter)) {
        $pInsertSqlite->bindValue(':mtimestamp', $batchId, SQLITE3_INTEGER);
        $pInsertSqlite->bindValue(':datname', $line["datname"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':pid', $line["pid"], SQLITE3_INTEGER);
        $pInsertSqlite->bindValue(':usename', $line["usename"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':client_addr', $line["client_addr"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':query_start', $line["query_start"], SQLITE3_INTEGER);
        $pInsertSqlite->bindValue(':timediff', $line["timediff"], SQLITE3_INTEGER);
        $pInsertSqlite->bindValue(':wait_event', $line["wait_event"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':state', $line["state"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':query', $line["query"], SQLITE3_TEXT);
        $pInsertSqlite->bindValue(':threshold', QUERY_DURATION_THRESHOLD, SQLITE3_INTEGER);
        $pInsertSqlite->bindValue(':application_name', $line["application_name"], SQLITE3_TEXT);
        $pInsertSqlite->execute();
        if (!$pInsertSqlite) {
          die("Error while inserting activity in sqlite database!".PHP_EOL);
        }
      }

      $crisisDuration= ($batchId-$batchIdFromFirstDetectionOfTheDefect);
      if ($recordCount >= QUERY_COUNT_ABOVE_THRESHOLD_ALERT and
          $crisisDuration > CRISIS_DURATION_FOR_ALERT) {
        print(PHP_EOL);
        $message = "PGQM (PostgreSQL Query Monitor)".PHP_EOL.
                     PHP_EOL.
                    "Alerta às ". date("d-m-Y H:i:s", time()).PHP_EOL.
                    "O banco de dados ".$infoPostgresqlDb["host"]." já está há mais de ".CRISIS_DURATION_FOR_ALERT." segundos com queries lentas ativas. ".PHP_EOL.
                    PHP_EOL.
                    "Número de queries lentas: $recordCount".PHP_EOL.
                    "ID da primeira coleta: $batchIdFromFirstDetectionOfTheDefect (".date("d-m-Y H:i:s", $batchIdFromFirstDetectionOfTheDefect).")".PHP_EOL.
                    "ID da última coleta: $batchId (".date("d-m-Y H:i:s", $batchId).")".PHP_EOL.
                    "Duração da crise: {$crisisDuration}s (por quanto tempo as queries lentas permanecem ativas)".PHP_EOL.
                    PHP_EOL.
                    "Configuração atual:".PHP_EOL.
                    "Tolerância: ".QUERY_DURATION_THRESHOLD."s (queries com duração maior que essa são consideradas lentas)".PHP_EOL.
                    "Número de queries lentas: ".QUERY_COUNT_ABOVE_THRESHOLD.PHP_EOL.
                    "Número de queries lentas para emitir alerta por e-mail: ".QUERY_COUNT_ABOVE_THRESHOLD_ALERT.PHP_EOL.
                    "Duração da crise: ".CRISIS_DURATION_FOR_ALERT."s (quanto tempo permanece em crise para daí enviar este alerta)".PHP_EOL.
                    PHP_EOL.
                    "Esta mensagem não será mais enviada nos próximos ".WAIT_TIME_FOR_ACTION." segundos.".PHP_EOL.
                    PHP_EOL.
                    "Estes são as queries mais lentas:".PHP_EOL.PHP_EOL.
        "PID | User | Cliente | Application name | Duração | Query".PHP_EOL;

        $sql = "select query, client_addr, timediff, pid, application_name, usename 
                from pgqm
                where mtimestamp = $batchId
                and timediff > ".QUERY_DURATION_THRESHOLD." and
                  (state = 'active' or wait_event <> 'ClientRead')
                order by timediff desc";
        $pSelMsg = $sqliteConnection->query($sql);

        while ($detailLine = $pSelMsg->fetchArray()) {
          $message .= "$detailLine[pid] | $detailLine[usename] | $detailLine[client_addr] | $detailLine[application_name] | $detailLine[timediff] | ".substr($detailLine["query"], 0, 120).PHP_EOL;
        }
        $message .= PHP_EOL."FIM!";
        print($message.PHP_EOL.PHP_EOL.PHP_EOL);

        if (time()-$lastSentMessageTimestamp >= WAIT_TIME_FOR_ACTION or
           $lastSentMessageTimestamp == null) {

          // Only send emails between 6 am and 23 am
          if (date('G') > 5) {
            send_email(SMTP_HOST, SMTP_PORT, ALERT_MAIL_SENDER, ALERT_MAIL_RECIPIENT, "PGQM Alerta", $message);
            $lastSentMessageTimestamp=time();

            // trigger
            if (strlen(TRIGGER_COMMAND) > 0) {
              foreach ($triggerServerList as $remoteServer) {
                print("Triggering $remoteServer...".PHP_EOL);

                $triggerCommand = TRIGGER_COMMAND;
                $triggerCommand = str_replace("###servidor###", $remoteServer, $triggerCommand);
                $triggerCommand = str_replace("###identificador###", $batchId, $triggerCommand);
                print("Running: $triggerCommand".PHP_EOL);
                $lastLine = exec($triggerCommand, $output, $returnCode);
                print("  Output: ".implode("#", $output).PHP_EOL);
                print("  Last line: $lastLine".PHP_EOL);
                print("  Return code: $returnCode".PHP_EOL);
                print(PHP_EOL);

              }
              print("Trigger commands sent to remote servers!".PHP_EOL.PHP_EOL);
            }
          }
        }

      }

    } else {
      print(".");
      $iterationRange=DEFAULT_ITERATION_RANGE;
      $batchIdFromFirstDetectionOfTheDefect=$batchId;
    }
    sleep($iterationRange);


    $iteration++;
    if ($iteration > 200) { // every X iterations, close sqlitedatabase to check size and rotate if necessary
      $iteration = 0;
      print("#");
      check_sqlite_database_size();
    }


  } // query loop

} // postgresql connection loop

function connect_sqlite_database() {
  global $sqliteConnection;
  global $pInsertSqlite;
  //print("Connecting to SQLite database...".PHP_EOL);
  $sqliteConnection = new SQLite3(SQLITE_DB_PATH);
  //$sqliteConnection->exec("DROP TABLE pgqm");
  if ($sqliteConnection->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='pgqm'") == null) {
    print("  New database detected. Creating table...".PHP_EOL);
    $sqliteConnection->exec("CREATE TABLE pgqm (mtimestamp integer, datname text, pid integer, usename text, client_addr text, query_start integer,
      timediff integer, wait_event text, state text, query text, threshold integer, application_name text)");
  }
  $pInsertSqlite = $sqliteConnection->prepare("INSERT INTO pgqm (mtimestamp, datname, pid, usename, client_addr, query_start, timediff, wait_event, state, query, threshold, application_name)
    VALUES (:mtimestamp, :datname, :pid, :usename, :client_addr, :query_start, :timediff, :wait_event, :state, :query, :threshold, :application_name)");
}

function check_sqlite_database_size() {
  global $sqliteConnection;
  if (!$sqliteConnection->close()) {
    print("Error closing SQLite database".PHP_EOL);
    return;
  }

  clearstatcache();
  $dbsize = filesize(SQLITE_DB_PATH);
  if ($dbsize > SQLITE_DB_MAX_SIZE) {
    print(PHP_EOL."*** SQLite database size excedeed ($dbsize > ".SQLITE_DB_MAX_SIZE.". Rotating...".PHP_EOL);
    $outputFile = SQLITE_DB_PATH."-".date("Ymd-His").".tar.bz2";
    $cmd = escapeshellcmd(TAR_PATH)." -jcvf $outputFile ".escapeshellarg(SQLITE_DB_PATH);
    print("Running: $cmd".PHP_EOL);
    $returnCode = 0;
    $lastLine = exec($cmd, $output, $returnCode);
    print("  Output: ".implode("#", $output).PHP_EOL);
    print("  Last line: $lastLine".PHP_EOL);
    print("  Return code: $returnCode".PHP_EOL);
    print(PHP_EOL);
    if ($returnCode == 0) {
      unlink(SQLITE_DB_PATH);
    }
  }

  connect_sqlite_database();
}

function get_postgresql_server_version($pgConnection) {
  $sql = "select version()";
  $resVersion = pg_query($pgConnection, $sql);
  $versionDetails = pg_fetch_result($resVersion, 0, "version");
  $version = substr($versionDetails, strpos($versionDetails, " ") + 1);
  $version = substr($version, 0, strpos($version, " "));
  return $version;
}

function send_email($smtpHost, $smtpPort, $sender, $recipient, $subject, $message) {
  $mail = new PHPMailer(true);
  try {
      //$mail->SMTPDebug = SMTP::DEBUG_SERVER;
      $mail->isSMTP();
      $mail->Host       = $smtpHost;
      $mail->SMTPAuth   = false;
      //$mail->Username   = 'user@example.com';
      //$mail->Password   = 'secret';
      //$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->SMTPSecure = false;
      $mail->SMTPAutoTLS = false;
      $mail->Port       = $smtpPort;

      $mail->setFrom($sender, 'PGQM Alerta');
      $mail->addAddress($recipient);

      $mail->isHTML(false);
      $mail->Subject = $subject;
      $mail->Body    = $message;

      $mail->send();
      echo 'Message has been sent';
  } catch (Exception $e) {
      echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
  }

}

?>
