CREATE TABLE bucket_schemas (
	`table_name` VARCHAR(255) NOT NULL,
	`table_version` INTEGER NOT NULL,
	`schema_json` TEXT NOT NULL,
	PRIMARY KEY (`table_name`,`table_version`)
);