<?php
// config/DBWrapper.php – a lightweight wrapper that mimics the mysqli API
// This allows existing code that uses ->prepare, ->bind_param, ->execute, and ->get_result()
// to work with a PDO connection to PostgreSQL (Supabase).

class DBWrapper {
    /** @var PDO */
    private $pdo;

    public function __construct(string $dsn, string $user, string $pass) {
        $this->pdo = new PDO($dsn, $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Emulate MySQL default charset handling (UTF8)
        $this->pdo->exec("SET NAMES 'utf8'" );
    }

    public function prepare(string $sql) {
        $stmt = $this->pdo->prepare($sql);
        return new StatementWrapper($stmt);
    }

    // For compatibility with code that may call set_charset on the connection.
    public function set_charset(string $charset) {
        $this->pdo->exec("SET NAMES '$charset'");
    }
}

class StatementWrapper {
    /** @var PDOStatement */
    private $stmt;

    public function __construct(PDOStatement $stmt) {
        $this->stmt = $stmt;
    }

    /**
     * Mimic mysqli_stmt::bind_param.  $types is a string like "iss".
     * Each subsequent argument is passed by reference (as in mysqli). We bind by value.
     */
    public function bind_param(string $types, &...$vars) {
        $count = strlen($types);
        for ($i = 0; $i < $count; $i++) {
            $var = $vars[$i] ?? null;
            $typeChar = $types[$i];
            $pdoType = PDO::PARAM_STR;
            if ($typeChar === 'i' || $typeChar === 'd') {
                $pdoType = PDO::PARAM_INT;
            }
            // PDO placeholders are 1‑based
            $this->stmt->bindValue($i + 1, $var, $pdoType);
        }
        return true;
    }

    public function execute() {
        return $this->stmt->execute();
    }

    public function get_result() {
        $data = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        return new ResultWrapper($data);
    }
}

class ResultWrapper {
    public $num_rows = 0;
    private $data;
    private $pointer = 0;

    public function __construct(array $data) {
        $this->data = $data;
        $this->num_rows = count($data);
    }

    public function fetch_assoc() {
        if ($this->pointer >= $this->num_rows) return null;
        return $this->data[$this->pointer++];
    }
}
?>
