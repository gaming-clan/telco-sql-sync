-- Variable, variation, simple, external and grouped products
CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `type` varchar(20),
  `parent` varchar(64),
  `sku` varchar(64),
  `name` varchar(255),
  `price` varchar(32),
  `stock` int(11),
  `attributes` varchar(255),
  `categories` varchar(255),
  `product_url` varchar(255),
  `grouped_skus` varchar(255),
  `upsells` varchar(255),
  `tax_class` varchar(64),
  `shipping_class` varchar(64),
  PRIMARY KEY (`id`)
);

INSERT INTO `items` VALUES
(1,'variable',NULL,'HOODIE','Hoodie',NULL,NULL,'Color: Black, Red | Size: M, L','Clothing > Hoodies',NULL,NULL,NULL,NULL,'heavy'),
(2,'variation','HOODIE','HOODIE-BLK-M',NULL,'49,90',5,'Color: Black | Size: M',NULL,NULL,NULL,NULL,NULL,NULL),
(3,'variation','HOODIE','HOODIE-RED-L',NULL,'54.90',2,'Color: Red | Size: L',NULL,NULL,NULL,NULL,NULL,NULL),
(4,'simple',NULL,'MUG','Mug','1,299.00',10,NULL,'Home',NULL,NULL,'HOODIE','reduced-rate',NULL),
(5,'external',NULL,'AFF-1','Affiliate Lamp','25',NULL,NULL,'Home','https://example.com/lamp',NULL,NULL,NULL,NULL),
(6,'grouped',NULL,'BUNDLE','Home Bundle',NULL,NULL,NULL,'Home',NULL,'MUG,AFF-1',NULL,NULL,NULL);
