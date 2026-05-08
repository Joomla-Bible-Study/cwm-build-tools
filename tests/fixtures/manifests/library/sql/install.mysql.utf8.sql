-- minimal install SQL fixture
CREATE TABLE IF NOT EXISTS `#__cwmscripture_test` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `created` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
