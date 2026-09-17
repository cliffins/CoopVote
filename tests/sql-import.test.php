<?php
/** SQL splitter regression: quoted delimiters and executable comments remain intact. */
require __DIR__.'/../includes/sql-import.php';
final class ImportRecorder extends PDO {
    public array $statements=[];
    public function __construct() {}
    public function exec(string $statement):int|false { $this->statements[]=$statement;return 0; }
}
$sql=<<<'SQL'
-- A comment containing ; is not a statement.
CREATE TABLE sample (`semi;name` TEXT);
INSERT INTO sample VALUES ('semi;colon'),('it\'s;fine'),('two''quotes;fine'),('/* text; */');
# Another ; comment
/*!40101 SET NAMES utf8mb4 */;
-- end
SQL;
$pdo=new ImportRecorder();sql_import($pdo,$sql);
if(count($pdo->statements)!==3)throw new RuntimeException('Statements were split inside data or comments.');
foreach(['semi;colon',"it\\'s;fine","two''quotes;fine",'/* text; */'] as $value)if(!str_contains($pdo->statements[1],$value))throw new RuntimeException('Quoted value changed.');
if(!str_contains($pdo->statements[2],'/*!40101'))throw new RuntimeException('Executable dump comment lost.');
$failed=false;try{sql_import(new ImportRecorder(),"INSERT INTO sample VALUES ('unfinished");}catch(RuntimeException $e){$failed=true;}
if(!$failed)throw new RuntimeException('Truncated SQL was accepted.');
echo "SQL import checks passed: quoted semicolons, escaped quotes, comments and truncated input.\n";
