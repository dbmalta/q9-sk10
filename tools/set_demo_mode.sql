INSERT INTO settings (`key`,`value`,`group`) VALUES ('install_mode','demo','general')
  ON DUPLICATE KEY UPDATE value='demo';
SELECT `key`,`value`,`group` FROM settings WHERE `key`='install_mode';
