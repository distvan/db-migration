-- Place here the initial database schema -->


-- End of schema creation

DELIMITER $$

DROP PROCEDURE IF EXISTS `AddColumnIfNotExists` $$

CREATE PROCEDURE `AddColumnIfNotExists`(
        IN tableName text,
        IN fieldName text,
        IN fieldDef text)
BEGIN
    IF NOT EXISTS(
        SELECT *
        FROM
            information_schema.columns
        WHERE
            `table_schema` COLLATE utf8_unicode_ci = DATABASE() AND
            `table_name` COLLATE utf8_unicode_ci =  tableName AND
            `column_name` COLLATE utf8_unicode_ci = fieldName
    )
    THEN
        SET @ddl = CONCAT('ALTER TABLE ', DATABASE(), '.', tableName, ' ADD COLUMN ', fieldName, ' ', fieldDef);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS `AddIndexIfNotExists` $$

CREATE PROCEDURE `AddIndexIfNotExists`(
        IN tableName text,
        IN indexName text,
        IN indexColumns text)
BEGIN
    IF NOT EXISTS(
        SELECT *
        FROM
            information_schema.statistics
        WHERE
            `table_schema` COLLATE utf8_unicode_ci = DATABASE() AND
            `table_name` COLLATE utf8_unicode_ci = tableName AND
            `index_name` COLLATE utf8_unicode_ci = indexName
    )
    THEN
        SET @ddl = CONCAT('CREATE INDEX ', indexName, ' ON ', DATABASE(), '.', tableName, ' (', indexColumns, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS `AddConstraintIfNotExists` $$

CREATE PROCEDURE `AddConstraintIfNotExists`(
        IN tableName text,
        IN fieldType text,
        IN fieldDef text)
BEGIN
    IF NOT EXISTS(
        SELECT *
        FROM
            information_schema.table_constraints
        WHERE
            `constraint_schema` COLLATE utf8_unicode_ci = DATABASE() AND
            `table_schema` COLLATE utf8_unicode_ci = DATABASE() AND
            `table_name` COLLATE utf8_unicode_ci = tableName AND
            `constraint_type` COLLATE utf8_unicode_ci = fieldType
    )
    THEN
        SET @ddl = CONCAT('ALTER TABLE ', DATABASE(), '.', tableName, ' ADD ', fieldType, ' ', fieldDef);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
    END IF;
END $$

DELIMITER ;