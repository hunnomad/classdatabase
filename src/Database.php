<?php

namespace HunNomad\Database;

use PDO;
use PDOException;

class Database {

    private ?PDO $connect = null;

    public function __construct(
        private string $host,
        private string $dbname,
        private string $user,
        private string $password,
        private string $port
    ) {}

    public function getConnection(): ?PDO
    {
        try {
            if ($this->connect === null) {
                $this->connect = new PDO(
                    "mysql:host=$this->host;port=$this->port;dbname={$this->dbname}",
                    $this->user,
                    $this->password
                );
                $this->connect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connect->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $this->connect->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            }
            return $this->connect;
        } catch (PDOException $e) {
            // Hibaüzenet összeállítása
            $errorMessage = sprintf(
                "[%s] Hiba történt az adatbázis kapcsolat létrehozása közben:\nFájl: %s\nFunkció: %s\nHibaüzenet: %s\n",
                date('Y-m-d H:i:s'),
                __FILE__,
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'ismeretlen',
                $e->getMessage()
            );

            // Konzolra kiírás
            echo $errorMessage;

            // Naplózás fájlba és rendszernaplóba
            $this->log($errorMessage);

            // Hibát dobunk, ha szükséges
            die("Connection failed. Részletek a logban találhatók.");
        }
    }

    private function log(string $message): void
    {
        // Fájlnaplózás
        $logFile = __DIR__ . '/error_log.txt';
        file_put_contents($logFile, $message, FILE_APPEND);

        // Rendszernaplózás `error_log()` segítségével
        error_log($message, 0); // Az üzenet az alapértelmezett rendszernaplóba kerül (pl. syslog)
    }

    public function __destruct()
    {
        $this->connect = null; // PDO kapcsolat explicit lezárása
    }
}


$sql = "SQL COMMAND";
$query = $pdo->prepare($sql);
$query->bindParam(':',$xxx,PDO::PARAM_STR);
$query->execute();
$r = $query->fetch(PDO::FETCH_OBJ);

?>
