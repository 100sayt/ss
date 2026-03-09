<?php
class MockPDO {
    public function query($sql) { return new MockStmt(); }
    public function prepare($sql) { return new MockStmt(); }
}
class MockStmt {
    public function fetch() { return false; }
    public function execute($args = []) {}
    public function fetchColumn() { return 0; }
}
$pdo = new MockPDO();
?>
