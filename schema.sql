-- Leere Datenbank fuer die Literaturverwaltung (MySQL 8.0.16 oder neuer).
-- Import: mysql -u BENUTZER -p < schema.sql
-- Fuer eine Neuinstallation; keine Migration einer bestehenden Datenbank.
-- Bei einem anderen Datenbanknamen CREATE DATABASE und USE anpassen.

SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS `Literatur`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `Literatur`;

CREATE TABLE `Journale` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `Titel` varchar(100) DEFAULT NULL,
  `ISSN` varchar(50) DEFAULT NULL,
  `Verlag` varchar(50) DEFAULT NULL,
  `Impact` float DEFAULT NULL,
  `URL` mediumtext,
  PRIMARY KEY (`ID`),
  KEY `idx_journale_titel` (`Titel`,`ID`),
  KEY `idx_journale_issn` (`ISSN`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `Artikel` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `Titel` varchar(255) DEFAULT NULL,
  `DOI` varchar(255) DEFAULT NULL,
  `Abstract` longtext,
  `JournalID` int DEFAULT NULL,
  `Jahrgang` int DEFAULT '0',
  `Band` varchar(50) DEFAULT NULL,
  `SeiteVon` int DEFAULT '0',
  `SeiteBis` int DEFAULT '0',
  `Standort` varchar(50) DEFAULT NULL,
  `Signatur` varchar(50) DEFAULT NULL,
  `Kopiert` bit(1) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_artikel_titel` (`Titel`,`ID`),
  KEY `idx_artikel_journal` (`JournalID`,`ID`),
  CONSTRAINT `fk_artikel_journal` FOREIGN KEY (`JournalID`) REFERENCES `Journale` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `Autoren` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `Name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_autoren_name` (`Name`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `Kategorien` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `Kategorie` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_kategorien_name` (`Kategorie`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `Projekte` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `Projekt` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_projekte_name` (`Projekt`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `AutorArtikel` (
  `AutorID` int NOT NULL,
  `ArtikelID` int NOT NULL,
  `Position` int NOT NULL,
  PRIMARY KEY (`ArtikelID`,`AutorID`),
  UNIQUE KEY `uq_artikel_autor_position` (`ArtikelID`,`Position`),
  KEY `idx_zuordnung_stamm` (`AutorID`,`ArtikelID`),
  CONSTRAINT `fk_AutorArtikel_artikel` FOREIGN KEY (`ArtikelID`) REFERENCES `Artikel` (`ID`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_AutorArtikel_stamm` FOREIGN KEY (`AutorID`) REFERENCES `Autoren` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_autor_position` CHECK (`Position` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `KategorieArtikel` (
  `KategorieID` int NOT NULL,
  `ArtikelID` int NOT NULL,
  PRIMARY KEY (`ArtikelID`,`KategorieID`),
  KEY `idx_zuordnung_stamm` (`KategorieID`,`ArtikelID`),
  CONSTRAINT `fk_KategorieArtikel_artikel` FOREIGN KEY (`ArtikelID`) REFERENCES `Artikel` (`ID`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_KategorieArtikel_stamm` FOREIGN KEY (`KategorieID`) REFERENCES `Kategorien` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ProjekteArtikel` (
  `ProjektID` int NOT NULL,
  `ArtikelID` int NOT NULL,
  PRIMARY KEY (`ArtikelID`,`ProjektID`),
  KEY `idx_zuordnung_stamm` (`ProjektID`,`ArtikelID`),
  CONSTRAINT `fk_ProjekteArtikel_artikel` FOREIGN KEY (`ArtikelID`) REFERENCES `Artikel` (`ID`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_ProjekteArtikel_stamm` FOREIGN KEY (`ProjektID`) REFERENCES `Projekte` (`ID`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `Anhaenge` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `ArtikelID` int NOT NULL,
  `Dateiname` varchar(255) NOT NULL,
  `MimeTyp` varchar(100) NOT NULL,
  `Dateigroesse` int unsigned NOT NULL,
  `SHA256` binary(32) NOT NULL,
  `Daten` longblob NOT NULL,
  `Position` int unsigned NOT NULL,
  `ErstelltAm` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_anhaenge_position` (`ArtikelID`,`Position`),
  KEY `idx_anhaenge_sha256` (`SHA256`),
  CONSTRAINT `fk_anhaenge_artikel` FOREIGN KEY (`ArtikelID`) REFERENCES `Artikel` (`ID`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_anhaenge_dateiname` CHECK (char_length(trim(`Dateiname`)) > 0),
  CONSTRAINT `chk_anhaenge_groesse` CHECK (`Dateigroesse` > 0 AND `Dateigroesse` = length(`Daten`)),
  CONSTRAINT `chk_anhaenge_position` CHECK (`Position` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
