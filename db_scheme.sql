CREATE TABLE `cities`
(
    `id` INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(30) NOT NULL,
    `lat` DOUBLE NOT NULL,
    `lng` DOUBLE NOT NULL,
    `is_bus_station` BIT NOT NULL
);

CREATE TABLE `passages`
(
    `id` INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
    `route_id` INT NOT NULL,
    `weekdays` BINARY(7) NOT NULL,
    `km_price` DOUBLE NOT NULL,
    `start_time` TIME NOT NULL
);

CREATE TABLE `routes`
(
    `id` INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
    `city_from` INT NOT NULL,
    `city_to` INT NOT NULL
);

CREATE TABLE `routes_waypoints`
(
    `id` INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
    `route_id` INT NOT NULL,
    `city_id` INT NOT NULL,
    `departure_time` INT,
    `arrival_time` INT,
    `distance` REAL NOT NULL
);

CREATE UNIQUE INDEX `title` ON `cities` (`title`);
CREATE UNIQUE INDEX `route_id` ON `routes_waypoints` (`route_id`, `city_id`);