<?php
// includes/db.php
class MockPDO {
    public function query($sql) {
        if (strpos($sql, 'categories') !== false) {
            return new MockStatement([
                ['id' => 1, 'name' => 'Elektronika', 'icon_path' => 'computer', 'parent_id' => 0],
                ['id' => 2, 'name' => 'Nəqliyyat', 'icon_path' => 'directions_car', 'parent_id' => 0],
                ['id' => 3, 'name' => 'Daşınmaz Əmlak', 'icon_path' => 'home', 'parent_id' => 0],
                ['id' => 4, 'name' => 'Telefonlar', 'icon_path' => '', 'parent_id' => 1]
            ]);
        }
        if (strpos($sql, 'cities') !== false) {
            return new MockStatement([
                ['id' => 1, 'name' => 'Bakı'],
                ['id' => 2, 'name' => 'Gəncə']
            ]);
        }
        if (strpos($sql, 'settings') !== false) {
            return new MockStatement([
                ['setting_key' => 'site_access_restricted', 'setting_value' => '0']
            ]);
        }
        return new MockStatement([]);
    }
    public function prepare($sql) {
        return new MockStatement([]);
    }
    public function exec($sql) {
        return 0;
    }
}
class MockStatement {
    private $data;
    private $index = 0;
    public function __construct($data) { $this->data = $data; }
    public function fetch() {
        if ($this->index < count($this->data)) {
            return $this->data[$this->index++];
        }
        return false;
    }
    public function fetchAll() { return $this->data; }
    public function fetchColumn() {
        if (!empty($this->data) && is_array($this->data[0])) {
            return reset($this->data[0]);
        }
        return false;
    }
    public function execute($params = []) { return true; }
}
$pdo = new MockPDO();
