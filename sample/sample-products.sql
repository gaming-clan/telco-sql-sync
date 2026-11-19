-- Sample dump for testing WooCommerce SQL Importer
/*!40101 SET NAMES utf8mb4 */;

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `description` text,
  `stock` int(11) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `image_url` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `products` VALUES
(1,'Blue T-Shirt','TSH-BLU','19.99',NULL,'Soft cotton; it''s "classic".\nTwo lines; with a semicolon.',25,'Clothing > Shirts',NULL),
(2,'Red Mug','MUG-RED','9.50','7.00','Ceramic mug -- dishwasher safe /* not a comment */',0,'Home,Kitchen',NULL),
(3,'Backslash \\ Test','BSL-001','1,299.00',NULL,'Escaped quote \' and unicode é ü',5,'Misc',NULL);

INSERT INTO `products` (`id`,`name`,`sku`,`price`) VALUES (4,'Plain Item','PLN-004',4.25);
