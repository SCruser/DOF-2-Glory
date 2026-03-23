-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 23, 2026 at 08:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `football_manager_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `Advance_Day` ()   BEGIN
    -- 1. Avansăm Data (Am pus `current_date` între backticks)
    UPDATE game_time 
    SET `current_date` = DATE_ADD(`current_date`, INTERVAL 1 DAY) 
    WHERE id = 1;

    -- 2. Rulăm Antrenamentele
    CALL Run_Daily_Training();

    -- 3. Recuperăm Stamina
    CALL Update_Player_Stamina();

    -- 4. Verificăm dacă e 1 ale lunii pentru contracte (Backticks și aici)
    IF DAY((SELECT `current_date` FROM game_time WHERE id = 1)) = 1 THEN
        CALL Process_Contracts_Monthly();
    END IF;

    -- 5. Curățăm jucătorii retrași
    CALL Retire_Old_Players();
    
    -- 6. Actualizăm starea Mercato
    UPDATE game_time 
    SET is_mercato_open = IF(MONTH((SELECT `current_date` FROM game_time WHERE id = 1)) IN (1, 7, 8), 1, 0)
    WHERE id = 1;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Decrement_Injuries` ()   BEGIN
    -- 1. Scădem o zi din accidentare pentru toți cei care au zile de recuperat
    UPDATE Player_core 
    SET injury_days_left = injury_days_left - 1 
    WHERE injury_days_left > 0;

    -- 2. Opțional: Putem genera o notificare pentru jucătorii care tocmai s-au vindecat
    -- (Dacă vrei să fie simplu pentru început, lăsăm doar update-ul de mai sus)
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Generate_Random_Manager` ()   BEGIN
    DECLARE v_first_name VARCHAR(50);
    DECLARE v_last_name VARCHAR(50);
    DECLARE v_full_name VARCHAR(100);
    DECLARE v_country VARCHAR(3);
    
    DECLARE v_formation_id INT;
    DECLARE v_mentality VARCHAR(20);
    DECLARE v_passing VARCHAR(20);
    DECLARE v_tempo INT;
    DECLARE v_def_line INT;
    DECLARE v_pressing INT;
    DECLARE v_salary DECIMAL(10,2);

    -- 1. Alegem o naționalitate la întâmplare din dicționar
    SELECT DISTINCT country_code INTO v_country FROM Name_Dictionary ORDER BY RAND() LIMIT 1;

    -- 2. Extragem un prenume random pentru acea țară
    SELECT name_text INTO v_first_name 
    FROM Name_Dictionary 
    WHERE name_type = 'First' AND country_code = v_country 
    ORDER BY RAND() LIMIT 1;

    -- 3. Extragem un nume de familie random pentru acea țară
    SELECT name_text INTO v_last_name 
    FROM Name_Dictionary 
    WHERE name_type = 'Last' AND country_code = v_country 
    ORDER BY RAND() LIMIT 1;

    SET v_full_name = CONCAT(v_first_name, ' ', v_last_name);

    -- 4. Extragem restul datelor tactice (la fel ca înainte)
    SELECT formation_id INTO v_formation_id FROM Formations ORDER BY RAND() LIMIT 1;
    
    SET v_mentality = ELT(FLOOR(1 + (RAND() * 5)), 'Defensive', 'Cautious', 'Balanced', 'Positive', 'Attacking');
    SET v_passing = ELT(FLOOR(1 + (RAND() * 3)), 'Shorter', 'Mixed', 'Direct');
    SET v_tempo = FLOOR(1 + (RAND() * 10));
    SET v_def_line = FLOOR(1 + (RAND() * 10));
    SET v_pressing = FLOOR(1 + (RAND() * 10));
    SET v_salary = FLOOR(5000 + (RAND() * 30000));

    -- 5. Salvăm antrenorul în sistem
    INSERT INTO Managers (name, team_id, salary, contract_months_left, preferred_formation_id, mentality, passing_style, tempo, defensive_line, pressing_intensity)
    VALUES (v_full_name, NULL, v_salary, 0, v_formation_id, v_mentality, v_passing, v_tempo, v_def_line, v_pressing);

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Generate_Youth_Intake` (IN `p_team_id` INT)   BEGIN
    DECLARE v_academy_lvl INT; DECLARE v_new_player_id INT; DECLARE v_potential INT;
    DECLARE v_country VARCHAR(3); DECLARE v_first_name VARCHAR(50); DECLARE v_last_name VARCHAR(50); DECLARE v_full_name VARCHAR(100);
    DECLARE v_is_gk BOOLEAN;

    SELECT IFNULL(youth_academy_level, 1) INTO v_academy_lvl FROM club_facilities WHERE team_id = p_team_id;

    SELECT DISTINCT country_code INTO v_country FROM name_dictionary ORDER BY RAND() LIMIT 1;
    SELECT name_text INTO v_first_name FROM name_dictionary WHERE name_type = 'First' AND country_code = v_country ORDER BY RAND() LIMIT 1;
    SELECT name_text INTO v_last_name FROM name_dictionary WHERE name_type = 'Last' AND country_code = v_country ORDER BY RAND() LIMIT 1;
    SET v_full_name = CONCAT(v_first_name, ' ', v_last_name);

    SET v_potential = LEAST(200, 50 + (v_academy_lvl * 12) + FLOOR(RAND() * 30));

    -- Stabilim dacă e portar (10% șansă)
    SET v_is_gk = IF(RAND() < 0.1, TRUE, FALSE);

    INSERT INTO player_core (team_id, country_code, name, age, is_youth) VALUES (p_team_id, v_country, v_full_name, 16, TRUE);
    SET v_new_player_id = LAST_INSERT_ID();

    INSERT INTO attributes_hidden (player_id, potential_ability) VALUES (v_new_player_id, v_potential);

    IF v_is_gk THEN
        -- Folosim EXACT coloanele tale din attributes_goalkeeper
        INSERT INTO attributes_goalkeeper (
            player_id, reflexes, handling, communication, one_on_ones, 
            aerial_reach, command_of_area, kicking, throwing, anticipation, 
            composure, determination, flair, workrate, teamwork, pace, stamina_max, strength
        ) VALUES (
            v_new_player_id, 
            10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 
            10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 
            10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 10+v_academy_lvl, 
            5+v_academy_lvl, 5+v_academy_lvl, 5+v_academy_lvl
        );
    ELSE
        -- Jucătorii de câmp (formula stabilită anterior)
        INSERT INTO attributes_outfield (player_id, pace, stamina_max, strength, finishing, dribbling, passing)
        VALUES (v_new_player_id, 10+v_academy_lvl, 10+v_academy_lvl, 5+v_academy_lvl, 5+v_academy_lvl, 5+v_academy_lvl, 5+v_academy_lvl);
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Populate_Manager_Market` ()   BEGIN
    DECLARE i INT DEFAULT 0;
    WHILE i < 10 DO
        CALL Generate_Random_Manager();
        SET i = i + 1;
    END WHILE;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Process_Contracts_Monthly` ()   BEGIN
    -- Rulăm asta doar dacă e prima zi a lunii (verificat în PHP sau Advance_Day)
    UPDATE managers 
    SET contract_months_left = GREATEST(0, contract_months_left - 1)
    WHERE team_id IS NOT NULL;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Process_Injuries` ()   BEGIN
    -- Presupunem că ai o coloană 'days_injured' în player_core sau o tabelă separată
    -- Dacă nu o ai încă, o lăsăm ca placeholder pentru când o implementăm
    -- UPDATE player_stats SET days_injured = GREATEST(0, days_injured - 1);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Retire_Old_Players` ()   BEGIN
    DELETE FROM player_core WHERE age > 40 AND team_id IS NULL;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Run_Daily_Training` ()   BEGIN
    -- Jucătorii tineri cu potențial mare cresc mai repede
    -- Folosim un mic factor aleator (0.01 - 0.05) pentru progresie
    UPDATE attributes_outfield ao
    JOIN attributes_hidden ah ON ao.player_id = ah.player_id
    JOIN player_core pc ON ao.player_id = pc.player_id
    SET ao.pace = ao.pace + (0.05 * (ah.potential_ability / 200)),
        ao.finishing = ao.finishing + (0.04 * (ah.potential_ability / 200))
    WHERE pc.age < 30; -- Doar jucătorii sub 30 de ani mai cresc semnificativ
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Send_Notification` (IN `p_user_id` INT, IN `p_type` VARCHAR(50), IN `p_title` VARCHAR(255), IN `p_content` TEXT, IN `p_ref_id` INT, IN `p_ref_type` VARCHAR(50))   BEGIN
    INSERT INTO Notification (user_id, type, title, content, reference_id, reference_type)
    VALUES (p_user_id, p_type, p_title, p_content, p_ref_id, p_ref_type);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Update_Player_CA` (IN `p_player_id` INT)   BEGIN
    DECLARE v_total_sum INT;

    -- Calculăm suma tuturor atributelor tehnice și fizice
    SELECT (crossing + dribbling + finishing + first_touch + heading + 
            long_shots + passing + set_pieces + composure + determination + 
            flair + workrate + teamwork + pace + stamina_max + strength + 
            marking + tackling)
    INTO v_total_sum
    FROM attributes_outfield
    WHERE player_id = p_player_id;

    -- Actualizăm valoarea în tabela hidden
    UPDATE attributes_hidden 
    SET current_ability = v_total_sum 
    WHERE player_id = p_player_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `Update_Player_Stamina` ()   BEGIN
    -- Recuperare naturală zilnică (ex: +10 stamina pe zi)
    UPDATE player_core 
    SET current_stamina = LEAST(100, current_stamina + 10)
    WHERE current_stamina < 100;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `academie`
--

CREATE TABLE `academie` (
  `academy_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `level` int(11) DEFAULT 1,
  `max_size` int(11) DEFAULT 15
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attributes_goalkeeper`
--

CREATE TABLE `attributes_goalkeeper` (
  `player_id` int(11) NOT NULL,
  `reflexes` tinyint(4) DEFAULT 10,
  `handling` tinyint(4) DEFAULT 10,
  `communication` tinyint(4) DEFAULT 10,
  `one_on_ones` tinyint(4) DEFAULT 10,
  `aerial_reach` tinyint(4) DEFAULT 10,
  `command_of_area` tinyint(4) DEFAULT 10,
  `kicking` tinyint(4) DEFAULT 10,
  `throwing` tinyint(4) DEFAULT 10,
  `anticipation` tinyint(4) DEFAULT 10,
  `composure` tinyint(4) DEFAULT 10,
  `determination` tinyint(4) DEFAULT 10,
  `flair` tinyint(4) DEFAULT 10,
  `workrate` tinyint(4) DEFAULT 10,
  `teamwork` tinyint(4) DEFAULT 10,
  `pace` tinyint(4) DEFAULT 10,
  `stamina_max` tinyint(4) DEFAULT 10,
  `strength` tinyint(4) DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attributes_goalkeeper`
--

INSERT INTO `attributes_goalkeeper` (`player_id`, `reflexes`, `handling`, `communication`, `one_on_ones`, `aerial_reach`, `command_of_area`, `kicking`, `throwing`, `anticipation`, `composure`, `determination`, `flair`, `workrate`, `teamwork`, `pace`, `stamina_max`, `strength`) VALUES
(1, 46, 33, 20, 31, 47, 48, 10, 18, 49, 20, 25, 15, 29, 50, 32, 42, 21),
(2, 12, 26, 45, 16, 14, 15, 23, 20, 21, 36, 24, 45, 18, 30, 44, 40, 15);

-- --------------------------------------------------------

--
-- Table structure for table `attributes_hidden`
--

CREATE TABLE `attributes_hidden` (
  `player_id` int(11) NOT NULL,
  `current_ability` int(11) DEFAULT 0,
  `potential_ability` int(11) DEFAULT 100,
  `weak_foot` tinyint(4) DEFAULT 10,
  `consistency` tinyint(4) DEFAULT 10,
  `dirtiness` tinyint(4) DEFAULT 10,
  `important_matches` tinyint(4) DEFAULT 10,
  `versatility` tinyint(4) DEFAULT 10,
  `injury_proneness` tinyint(4) DEFAULT 10,
  `adaptability` tinyint(4) DEFAULT 10,
  `ambition` tinyint(4) DEFAULT 10,
  `professionalism` tinyint(4) DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attributes_hidden`
--

INSERT INTO `attributes_hidden` (`player_id`, `current_ability`, `potential_ability`, `weak_foot`, `consistency`, `dirtiness`, `important_matches`, `versatility`, `injury_proneness`, `adaptability`, `ambition`, `professionalism`) VALUES
(1, 0, 150, 42, 37, 50, 49, 42, 15, 22, 11, 23),
(2, 0, 158, 38, 47, 26, 23, 26, 11, 48, 34, 15),
(3, 0, 185, 18, 21, 43, 17, 30, 46, 11, 29, 20),
(4, 0, 183, 39, 44, 15, 12, 48, 33, 49, 13, 32),
(5, 0, 155, 46, 46, 43, 27, 36, 50, 10, 14, 31),
(6, 0, 139, 42, 17, 31, 13, 45, 10, 31, 33, 21),
(7, 0, 170, 38, 25, 42, 42, 32, 27, 30, 19, 38),
(8, 0, 169, 26, 50, 39, 34, 44, 25, 27, 50, 34),
(9, 0, 125, 34, 38, 41, 38, 19, 12, 33, 37, 35),
(10, 0, 125, 33, 36, 30, 33, 24, 10, 10, 11, 16),
(11, 0, 164, 38, 36, 12, 27, 48, 27, 23, 23, 37),
(12, 0, 145, 42, 45, 49, 17, 10, 30, 31, 15, 14),
(13, 0, 125, 13, 18, 43, 28, 41, 31, 21, 45, 29),
(14, 0, 172, 21, 18, 16, 15, 21, 47, 42, 19, 41),
(15, 0, 132, 33, 22, 45, 23, 15, 35, 41, 48, 24),
(16, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(17, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(18, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(19, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(20, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(21, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(22, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(23, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(24, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10),
(25, 0, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10);

-- --------------------------------------------------------

--
-- Table structure for table `attributes_outfield`
--

CREATE TABLE `attributes_outfield` (
  `player_id` int(11) NOT NULL,
  `crossing` tinyint(4) DEFAULT 10,
  `dribbling` tinyint(4) DEFAULT 10,
  `finishing` tinyint(4) DEFAULT 10,
  `first_touch` tinyint(4) DEFAULT 10,
  `heading` tinyint(4) DEFAULT 10,
  `long_shots` tinyint(4) DEFAULT 10,
  `passing` tinyint(4) DEFAULT 10,
  `marking` tinyint(4) DEFAULT 10,
  `tackling` tinyint(4) DEFAULT 10,
  `set_pieces` tinyint(4) DEFAULT 10,
  `composure` tinyint(4) DEFAULT 10,
  `determination` tinyint(4) DEFAULT 10,
  `flair` tinyint(4) DEFAULT 10,
  `workrate` tinyint(4) DEFAULT 10,
  `teamwork` tinyint(4) DEFAULT 10,
  `pace` tinyint(4) DEFAULT 10,
  `stamina_max` tinyint(4) DEFAULT 10,
  `strength` tinyint(4) DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attributes_outfield`
--

INSERT INTO `attributes_outfield` (`player_id`, `crossing`, `dribbling`, `finishing`, `first_touch`, `heading`, `long_shots`, `passing`, `marking`, `tackling`, `set_pieces`, `composure`, `determination`, `flair`, `workrate`, `teamwork`, `pace`, `stamina_max`, `strength`) VALUES
(3, 10, 27, 14, 15, 28, 44, 10, 40, 23, 23, 38, 26, 10, 44, 18, 29, 39, 17),
(4, 42, 27, 41, 27, 48, 26, 10, 15, 28, 48, 22, 33, 12, 32, 33, 21, 34, 19),
(5, 22, 45, 26, 20, 19, 25, 10, 12, 21, 17, 14, 44, 48, 20, 25, 18, 44, 35),
(6, 32, 44, 37, 37, 27, 15, 10, 23, 20, 21, 39, 36, 13, 29, 15, 19, 39, 48),
(7, 33, 10, 23, 32, 40, 17, 10, 33, 26, 18, 47, 45, 34, 25, 14, 27, 41, 33),
(8, 33, 14, 46, 10, 26, 12, 10, 48, 34, 16, 13, 40, 33, 36, 30, 34, 28, 31),
(9, 18, 28, 38, 10, 12, 21, 10, 15, 45, 48, 13, 28, 16, 26, 32, 30, 44, 39),
(10, 11, 13, 22, 20, 24, 50, 10, 44, 22, 48, 42, 17, 31, 10, 32, 40, 50, 40),
(11, 40, 28, 12, 43, 48, 23, 10, 38, 32, 35, 30, 32, 23, 10, 11, 16, 38, 49),
(12, 40, 43, 47, 13, 39, 23, 10, 30, 30, 10, 31, 35, 33, 49, 15, 40, 21, 18),
(13, 16, 16, 24, 22, 26, 15, 10, 28, 44, 46, 48, 10, 21, 25, 10, 47, 32, 11),
(14, 30, 27, 36, 42, 12, 11, 10, 44, 18, 29, 43, 29, 12, 45, 15, 11, 43, 47),
(15, 18, 20, 37, 31, 37, 39, 10, 34, 42, 18, 36, 35, 17, 10, 32, 37, 39, 35),
(16, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(17, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(18, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(19, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(20, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(21, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(22, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(23, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(24, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL),
(25, 10, NULL, NULL, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, NULL, NULL, NULL);

--
-- Triggers `attributes_outfield`
--
DELIMITER $$
CREATE TRIGGER `after_attributes_update` AFTER UPDATE ON `attributes_outfield` FOR EACH ROW BEGIN
    -- De fiecare dată când se schimbă atributele, recalculăm CA-ul
    CALL Update_Player_CA(NEW.player_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bid`
--

CREATE TABLE `bid` (
  `bid_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `bidding_team_id` int(11) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `club_facilities`
--

CREATE TABLE `club_facilities` (
  `team_id` int(11) NOT NULL,
  `training_level` int(11) DEFAULT 1,
  `youth_academy_level` int(11) DEFAULT 1,
  `medical_level` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `countrycoefficient`
--

CREATE TABLE `countrycoefficient` (
  `country_code` varchar(3) NOT NULL,
  `country_name` varchar(100) NOT NULL,
  `coefficient` decimal(3,2) DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `countrycoefficient`
--

INSERT INTO `countrycoefficient` (`country_code`, `country_name`, `coefficient`) VALUES
('ARG', 'Argentina', 1.00),
('BRA', 'Brazilia', 1.00),
('ENG', 'Anglia', 1.00),
('ESP', 'Spania', 1.00),
('FRA', 'Franta', 1.00),
('GER', 'Germania', 1.00),
('ITA', 'Italia', 1.00),
('JPN', 'Japonia', 1.00),
('NGA', 'Nigeria', 1.00),
('ROU', 'Romania', 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `expenditure_categories`
--

CREATE TABLE `expenditure_categories` (
  `exp_cat_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `financial_ledger`
--

CREATE TABLE `financial_ledger` (
  `transaction_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `income_cat_id` int(11) DEFAULT NULL,
  `exp_cat_id` int(11) DEFAULT NULL,
  `amount` bigint(20) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `transaction_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `formations`
--

CREATE TABLE `formations` (
  `formation_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `gk` int(11) DEFAULT 1 CHECK (`gk` = 1),
  `lb` int(11) DEFAULT 0,
  `cb` int(11) DEFAULT 0,
  `rb` int(11) DEFAULT 0,
  `cdm` int(11) DEFAULT 0,
  `cm` int(11) DEFAULT 0,
  `cam` int(11) DEFAULT 0,
  `lm` int(11) DEFAULT 0,
  `rm` int(11) DEFAULT 0,
  `lw` int(11) DEFAULT 0,
  `rw` int(11) DEFAULT 0,
  `st` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `formations`
--

INSERT INTO `formations` (`formation_id`, `name`, `gk`, `lb`, `cb`, `rb`, `cdm`, `cm`, `cam`, `lm`, `rm`, `lw`, `rw`, `st`) VALUES
(1, '3-1-4-2', 1, 0, 3, 0, 1, 2, 0, 1, 1, 0, 0, 2),
(2, '3-4-1-2', 1, 0, 3, 0, 0, 2, 1, 1, 1, 0, 0, 2),
(3, '3-4-2-1', 1, 0, 3, 0, 0, 2, 2, 1, 1, 0, 0, 1),
(4, '3-4-3', 1, 0, 3, 0, 0, 2, 0, 1, 1, 1, 1, 1),
(5, '3-5-2', 1, 0, 3, 0, 2, 0, 1, 1, 1, 0, 0, 2),
(6, '4-1-2-1-2 Wide', 1, 1, 2, 1, 1, 0, 1, 1, 1, 0, 0, 2),
(7, '4-1-2-1-2 Narrow', 1, 1, 2, 1, 1, 2, 1, 0, 0, 0, 0, 2),
(8, '4-1-3-2', 1, 1, 2, 1, 1, 1, 0, 1, 1, 0, 0, 2),
(9, '4-1-4-1', 1, 1, 2, 1, 1, 2, 0, 1, 1, 0, 0, 1),
(10, '4-2-1-3', 1, 1, 2, 1, 2, 0, 1, 0, 0, 1, 1, 1),
(11, '4-2-2-2', 1, 1, 2, 1, 2, 0, 2, 0, 0, 0, 0, 2),
(12, '4-2-3-1 Narrow', 1, 1, 2, 1, 2, 0, 3, 0, 0, 0, 0, 1),
(13, '4-2-3-1 Wide', 1, 1, 2, 1, 2, 0, 1, 1, 1, 0, 0, 1),
(14, '4-2-4', 1, 1, 2, 1, 0, 2, 0, 0, 0, 1, 1, 2),
(15, '4-3-1-2', 1, 1, 2, 1, 0, 3, 1, 0, 0, 0, 0, 2),
(16, '4-3-2-1', 1, 1, 2, 1, 0, 3, 2, 0, 0, 0, 0, 1),
(17, '4-3-3 Flat', 1, 1, 2, 1, 0, 3, 0, 0, 0, 1, 1, 1),
(18, '4-3-3 Deep', 1, 1, 2, 1, 1, 2, 0, 0, 0, 1, 1, 1),
(19, '4-3-3 Defensive', 1, 1, 2, 1, 2, 1, 0, 0, 0, 1, 1, 1),
(20, '4-3-3 Offensiv', 1, 1, 2, 1, 0, 2, 1, 0, 0, 1, 1, 1),
(21, '4-4-1-1', 1, 1, 2, 1, 0, 2, 1, 1, 1, 0, 0, 1),
(22, '4-4-2 Flat', 1, 1, 2, 1, 0, 2, 0, 1, 1, 0, 0, 2),
(23, '4-4-2 Defensiv', 1, 1, 2, 1, 2, 0, 0, 1, 1, 0, 0, 2),
(24, '4-5-1 Ofensiv', 1, 1, 2, 1, 0, 1, 2, 1, 1, 0, 0, 1),
(25, '4-5-1 Flat', 1, 1, 2, 1, 0, 3, 0, 1, 1, 0, 0, 1),
(26, '5-2-1-2', 1, 1, 3, 1, 0, 2, 1, 0, 0, 0, 0, 2),
(27, '5-2-3', 1, 1, 3, 1, 0, 2, 0, 0, 0, 1, 1, 1),
(28, '5-3-2', 1, 1, 3, 1, 1, 2, 0, 0, 0, 0, 0, 2),
(29, '5-4-1', 1, 1, 3, 1, 0, 2, 0, 1, 1, 0, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `game_settings`
--

CREATE TABLE `game_settings` (
  `setting_id` int(11) NOT NULL DEFAULT 1,
  `current_game_date` date NOT NULL,
  `season_start_date` date NOT NULL,
  `is_paused` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `game_settings`
--

INSERT INTO `game_settings` (`setting_id`, `current_game_date`, `season_start_date`, `is_paused`) VALUES
(1, '2026-07-03', '2026-07-01', 0);

-- --------------------------------------------------------

--
-- Table structure for table `income_categories`
--

CREATE TABLE `income_categories` (
  `income_cat_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `league_instances`
--

CREATE TABLE `league_instances` (
  `instance_id` int(11) NOT NULL,
  `tier_level` int(11) NOT NULL,
  `instance_name` varchar(100) DEFAULT NULL,
  `season_number` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `league_instances`
--

INSERT INTO `league_instances` (`instance_id`, `tier_level`, `instance_name`, `season_number`) VALUES
(1, 1, 'Liga Global DOF', 1);

-- --------------------------------------------------------

--
-- Table structure for table `league_standings`
--

CREATE TABLE `league_standings` (
  `instance_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `played` int(11) DEFAULT 0,
  `won` int(11) DEFAULT 0,
  `drawn` int(11) DEFAULT 0,
  `lost` int(11) DEFAULT 0,
  `goals_for` int(11) DEFAULT 0,
  `goals_against` int(11) DEFAULT 0,
  `points` int(11) DEFAULT 0,
  `final_position` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `league_tiers`
--

CREATE TABLE `league_tiers` (
  `tier_level` int(11) NOT NULL,
  `promotion_prize` bigint(20) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `league_tiers`
--

INSERT INTO `league_tiers` (`tier_level`, `promotion_prize`) VALUES
(1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `manager`
--

CREATE TABLE `manager` (
  `manager_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `preferred_formation` varchar(10) DEFAULT '4-4-2',
  `approach_style` enum('Defensive','Balanced','Attacking','Gegenpressing') DEFAULT 'Balanced',
  `tactical_knowledge` tinyint(4) DEFAULT 25
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `managers`
--

CREATE TABLE `managers` (
  `manager_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT 0.00,
  `contract_months_left` int(11) DEFAULT 0,
  `preferred_formation_id` int(11) DEFAULT NULL,
  `mentality` enum('Defensive','Cautious','Balanced','Positive','Attacking') NOT NULL,
  `passing_style` enum('Shorter','Mixed','Direct') NOT NULL,
  `tempo` int(11) NOT NULL,
  `defensive_line` int(11) NOT NULL,
  `pressing_intensity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `managers`
--

INSERT INTO `managers` (`manager_id`, `name`, `team_id`, `salary`, `contract_months_left`, `preferred_formation_id`, `mentality`, `passing_style`, `tempo`, `defensive_line`, `pressing_intensity`) VALUES
(1, 'Robert Smith', NULL, 13718.00, 0, 2, 'Attacking', 'Direct', 1, 8, 8),
(2, 'Victor Diaz', NULL, 30278.00, 0, 25, 'Attacking', 'Shorter', 1, 9, 9),
(3, 'Hans Koch', NULL, 21680.00, 0, 14, 'Balanced', 'Direct', 6, 8, 2),
(4, 'Francisco Fernandez', NULL, 34472.00, 0, 5, 'Positive', 'Direct', 6, 1, 5),
(5, 'Pierre Michel', NULL, 14573.00, 0, 24, 'Defensive', 'Direct', 7, 9, 7),
(6, 'Jose Perez', NULL, 6835.00, 0, 8, 'Balanced', 'Mixed', 6, 2, 9),
(7, 'Mihai Stan', NULL, 14475.00, 0, 10, 'Cautious', 'Direct', 2, 10, 3),
(8, 'Gabriel Ferreira', 1, 34644.00, 24, 7, 'Positive', 'Mixed', 9, 5, 7),
(9, 'Patrick Thomas', NULL, 25474.00, 0, 9, 'Balanced', 'Shorter', 10, 6, 9),
(10, 'Charles White', NULL, 13410.00, 0, 14, 'Positive', 'Mixed', 10, 9, 4),
(11, 'Eduardo Ruiz', NULL, 31624.00, 0, 11, 'Attacking', 'Shorter', 2, 2, 3),
(12, 'Eric Michel', NULL, 9440.00, 0, 9, 'Balanced', 'Mixed', 9, 7, 9),
(13, 'Luiz Gomes', NULL, 25351.00, 0, 25, 'Balanced', 'Shorter', 2, 5, 9),
(14, 'David Martinez', NULL, 31447.00, 0, 27, 'Positive', 'Mixed', 5, 1, 1),
(15, 'Mattia Marino', NULL, 22484.00, 0, 21, 'Positive', 'Direct', 7, 10, 7),
(16, 'Christian Schmidt', NULL, 9672.00, 0, 16, 'Defensive', 'Shorter', 3, 9, 6),
(17, 'Adrian Gheorghe', NULL, 21484.00, 0, 8, 'Balanced', 'Direct', 1, 7, 9),
(18, 'Victor Diaz', NULL, 18622.00, 0, 19, 'Positive', 'Mixed', 4, 6, 4),
(19, 'Luis Martinez', NULL, 30961.00, 0, 27, 'Balanced', 'Mixed', 6, 9, 7),
(20, 'Andrea Gallo', NULL, 18645.00, 0, 1, 'Cautious', 'Direct', 4, 6, 6);

-- --------------------------------------------------------

--
-- Table structure for table `match_fixture`
--

CREATE TABLE `match_fixture` (
  `match_id` int(11) NOT NULL,
  `instance_id` int(11) NOT NULL,
  `home_team_id` int(11) NOT NULL,
  `away_team_id` int(11) NOT NULL,
  `match_date` datetime NOT NULL,
  `home_score` tinyint(4) DEFAULT 0,
  `away_score` tinyint(4) DEFAULT 0,
  `status` enum('Scheduled','Live','Finished') DEFAULT 'Scheduled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `name_dictionary`
--

CREATE TABLE `name_dictionary` (
  `id` int(11) NOT NULL,
  `name_text` varchar(50) NOT NULL,
  `name_type` enum('First','Last') NOT NULL,
  `country_code` varchar(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `name_dictionary`
--

INSERT INTO `name_dictionary` (`id`, `name_text`, `name_type`, `country_code`) VALUES
(1, 'Ion', 'First', 'ROU'),
(2, 'Andrei', 'First', 'ROU'),
(3, 'Mihai', 'First', 'ROU'),
(4, 'Adrian', 'First', 'ROU'),
(5, 'Florin', 'First', 'ROU'),
(6, 'Nicolae', 'First', 'ROU'),
(7, 'Cristian', 'First', 'ROU'),
(8, 'Gheorghe', 'First', 'ROU'),
(9, 'Bogdan', 'First', 'ROU'),
(10, 'Stefan', 'First', 'ROU'),
(11, 'Radu', 'First', 'ROU'),
(12, 'Lucian', 'First', 'ROU'),
(13, 'Mircea', 'First', 'ROU'),
(14, 'Dan', 'First', 'ROU'),
(15, 'Victor', 'First', 'ROU'),
(16, 'Popescu', 'Last', 'ROU'),
(17, 'Ionescu', 'Last', 'ROU'),
(18, 'Radu', 'Last', 'ROU'),
(19, 'Dumitrescu', 'Last', 'ROU'),
(20, 'Stan', 'Last', 'ROU'),
(21, 'Stoica', 'Last', 'ROU'),
(22, 'Gheorghe', 'Last', 'ROU'),
(23, 'Matei', 'Last', 'ROU'),
(24, 'Munteanu', 'Last', 'ROU'),
(25, 'Balan', 'Last', 'ROU'),
(26, 'Petrescu', 'Last', 'ROU'),
(27, 'Ilie', 'Last', 'ROU'),
(28, 'Marin', 'Last', 'ROU'),
(29, 'Hagi', 'Last', 'ROU'),
(30, 'Lupescu', 'Last', 'ROU'),
(31, 'James', 'First', 'ENG'),
(32, 'John', 'First', 'ENG'),
(33, 'Robert', 'First', 'ENG'),
(34, 'Michael', 'First', 'ENG'),
(35, 'William', 'First', 'ENG'),
(36, 'David', 'First', 'ENG'),
(37, 'Richard', 'First', 'ENG'),
(38, 'Joseph', 'First', 'ENG'),
(39, 'Thomas', 'First', 'ENG'),
(40, 'Charles', 'First', 'ENG'),
(41, 'Harry', 'First', 'ENG'),
(42, 'Jack', 'First', 'ENG'),
(43, 'Oliver', 'First', 'ENG'),
(44, 'Charlie', 'First', 'ENG'),
(45, 'George', 'First', 'ENG'),
(46, 'Smith', 'Last', 'ENG'),
(47, 'Johnson', 'Last', 'ENG'),
(48, 'Williams', 'Last', 'ENG'),
(49, 'Brown', 'Last', 'ENG'),
(50, 'Jones', 'Last', 'ENG'),
(51, 'Garcia', 'Last', 'ENG'),
(52, 'Miller', 'Last', 'ENG'),
(53, 'Davis', 'Last', 'ENG'),
(54, 'Rodriguez', 'Last', 'ENG'),
(55, 'Martinez', 'Last', 'ENG'),
(56, 'Taylor', 'Last', 'ENG'),
(57, 'Anderson', 'Last', 'ENG'),
(58, 'Wilson', 'Last', 'ENG'),
(59, 'Moore', 'Last', 'ENG'),
(60, 'White', 'Last', 'ENG'),
(61, 'Antonio', 'First', 'ESP'),
(62, 'Jose', 'First', 'ESP'),
(63, 'Manuel', 'First', 'ESP'),
(64, 'Francisco', 'First', 'ESP'),
(65, 'David', 'First', 'ESP'),
(66, 'Juan', 'First', 'ESP'),
(67, 'Javier', 'First', 'ESP'),
(68, 'Daniel', 'First', 'ESP'),
(69, 'Carlos', 'First', 'ESP'),
(70, 'Jesus', 'First', 'ESP'),
(71, 'Alejandro', 'First', 'ESP'),
(72, 'Miguel', 'First', 'ESP'),
(73, 'Pedro', 'First', 'ESP'),
(74, 'Luis', 'First', 'ESP'),
(75, 'Sergio', 'First', 'ESP'),
(76, 'Garcia', 'Last', 'ESP'),
(77, 'Rodriguez', 'Last', 'ESP'),
(78, 'Gonzalez', 'Last', 'ESP'),
(79, 'Fernandez', 'Last', 'ESP'),
(80, 'Lopez', 'Last', 'ESP'),
(81, 'Martinez', 'Last', 'ESP'),
(82, 'Sanchez', 'Last', 'ESP'),
(83, 'Perez', 'Last', 'ESP'),
(84, 'Gomez', 'Last', 'ESP'),
(85, 'Martin', 'Last', 'ESP'),
(86, 'Ruiz', 'Last', 'ESP'),
(87, 'Hernandez', 'Last', 'ESP'),
(88, 'Diaz', 'Last', 'ESP'),
(89, 'Moreno', 'Last', 'ESP'),
(90, 'Alvarez', 'Last', 'ESP'),
(91, 'Francesco', 'First', 'ITA'),
(92, 'Alessandro', 'First', 'ITA'),
(93, 'Leonardo', 'First', 'ITA'),
(94, 'Lorenzo', 'First', 'ITA'),
(95, 'Mattia', 'First', 'ITA'),
(96, 'Andrea', 'First', 'ITA'),
(97, 'Gabriele', 'First', 'ITA'),
(98, 'Riccardo', 'First', 'ITA'),
(99, 'Matteo', 'First', 'ITA'),
(100, 'Tommaso', 'First', 'ITA'),
(101, 'Giuseppe', 'First', 'ITA'),
(102, 'Antonio', 'First', 'ITA'),
(103, 'Giovanni', 'First', 'ITA'),
(104, 'Roberto', 'First', 'ITA'),
(105, 'Luigi', 'First', 'ITA'),
(106, 'Rossi', 'Last', 'ITA'),
(107, 'Russo', 'Last', 'ITA'),
(108, 'Ferrari', 'Last', 'ITA'),
(109, 'Esposito', 'Last', 'ITA'),
(110, 'Bianchi', 'Last', 'ITA'),
(111, 'Romano', 'Last', 'ITA'),
(112, 'Colombo', 'Last', 'ITA'),
(113, 'Ricci', 'Last', 'ITA'),
(114, 'Marino', 'Last', 'ITA'),
(115, 'Greco', 'Last', 'ITA'),
(116, 'Bruno', 'Last', 'ITA'),
(117, 'Gallo', 'Last', 'ITA'),
(118, 'Conti', 'Last', 'ITA'),
(119, 'De Luca', 'Last', 'ITA'),
(120, 'Mancini', 'Last', 'ITA'),
(121, 'Thomas', 'First', 'GER'),
(122, 'Michael', 'First', 'GER'),
(123, 'Andreas', 'First', 'GER'),
(124, 'Peter', 'First', 'GER'),
(125, 'Christian', 'First', 'GER'),
(126, 'Stefan', 'First', 'GER'),
(127, 'Klaus', 'First', 'GER'),
(128, 'Jurgen', 'First', 'GER'),
(129, 'Martin', 'First', 'GER'),
(130, 'Frank', 'First', 'GER'),
(131, 'Dieter', 'First', 'GER'),
(132, 'Hans', 'First', 'GER'),
(133, 'Bernd', 'First', 'GER'),
(134, 'Uwe', 'First', 'GER'),
(135, 'Matthias', 'First', 'GER'),
(136, 'Muller', 'Last', 'GER'),
(137, 'Schmidt', 'Last', 'GER'),
(138, 'Schneider', 'Last', 'GER'),
(139, 'Fischer', 'Last', 'GER'),
(140, 'Weber', 'Last', 'GER'),
(141, 'Meyer', 'Last', 'GER'),
(142, 'Wagner', 'Last', 'GER'),
(143, 'Becker', 'Last', 'GER'),
(144, 'Schulz', 'Last', 'GER'),
(145, 'Hoffmann', 'Last', 'GER'),
(146, 'Schafer', 'Last', 'GER'),
(147, 'Koch', 'Last', 'GER'),
(148, 'Bauer', 'Last', 'GER'),
(149, 'Richter', 'Last', 'GER'),
(150, 'Klein', 'Last', 'GER'),
(151, 'Jean', 'First', 'FRA'),
(152, 'Michel', 'First', 'FRA'),
(153, 'Pierre', 'First', 'FRA'),
(154, 'Philippe', 'First', 'FRA'),
(155, 'Alain', 'First', 'FRA'),
(156, 'Patrick', 'First', 'FRA'),
(157, 'Nicolas', 'First', 'FRA'),
(158, 'Christophe', 'First', 'FRA'),
(159, 'Christian', 'First', 'FRA'),
(160, 'Daniel', 'First', 'FRA'),
(161, 'Thierry', 'First', 'FRA'),
(162, 'Laurent', 'First', 'FRA'),
(163, 'Bernard', 'First', 'FRA'),
(164, 'Eric', 'First', 'FRA'),
(165, 'Julien', 'First', 'FRA'),
(166, 'Martin', 'Last', 'FRA'),
(167, 'Bernard', 'Last', 'FRA'),
(168, 'Thomas', 'Last', 'FRA'),
(169, 'Petit', 'Last', 'FRA'),
(170, 'Robert', 'Last', 'FRA'),
(171, 'Richard', 'Last', 'FRA'),
(172, 'Durand', 'Last', 'FRA'),
(173, 'Dubois', 'Last', 'FRA'),
(174, 'Moreau', 'Last', 'FRA'),
(175, 'Laurent', 'Last', 'FRA'),
(176, 'Simon', 'Last', 'FRA'),
(177, 'Michel', 'Last', 'FRA'),
(178, 'Lefebvre', 'Last', 'FRA'),
(179, 'Leroy', 'Last', 'FRA'),
(180, 'Roux', 'Last', 'FRA'),
(181, 'Jose', 'First', 'BRA'),
(182, 'Joao', 'First', 'BRA'),
(183, 'Antonio', 'First', 'BRA'),
(184, 'Francisco', 'First', 'BRA'),
(185, 'Carlos', 'First', 'BRA'),
(186, 'Paulo', 'First', 'BRA'),
(187, 'Pedro', 'First', 'BRA'),
(188, 'Lucas', 'First', 'BRA'),
(189, 'Luiz', 'First', 'BRA'),
(190, 'Marcos', 'First', 'BRA'),
(191, 'Luis', 'First', 'BRA'),
(192, 'Gabriel', 'First', 'BRA'),
(193, 'Rafael', 'First', 'BRA'),
(194, 'Daniel', 'First', 'BRA'),
(195, 'Marcelo', 'First', 'BRA'),
(196, 'Silva', 'Last', 'BRA'),
(197, 'Santos', 'Last', 'BRA'),
(198, 'Oliveira', 'Last', 'BRA'),
(199, 'Souza', 'Last', 'BRA'),
(200, 'Rodrigues', 'Last', 'BRA'),
(201, 'Ferreira', 'Last', 'BRA'),
(202, 'Alves', 'Last', 'BRA'),
(203, 'Pereira', 'Last', 'BRA'),
(204, 'Lima', 'Last', 'BRA'),
(205, 'Gomes', 'Last', 'BRA'),
(206, 'Costa', 'Last', 'BRA'),
(207, 'Ribeiro', 'Last', 'BRA'),
(208, 'Martins', 'Last', 'BRA'),
(209, 'Carvalho', 'Last', 'BRA'),
(210, 'Almeida', 'Last', 'BRA'),
(211, 'Juan', 'First', 'ARG'),
(212, 'Carlos', 'First', 'ARG'),
(213, 'Jose', 'First', 'ARG'),
(214, 'Jorge', 'First', 'ARG'),
(215, 'Luis', 'First', 'ARG'),
(216, 'Miguel', 'First', 'ARG'),
(217, 'Victor', 'First', 'ARG'),
(218, 'Hector', 'First', 'ARG'),
(219, 'Daniel', 'First', 'ARG'),
(220, 'Oscar', 'First', 'ARG'),
(221, 'Eduardo', 'First', 'ARG'),
(222, 'Diego', 'First', 'ARG'),
(223, 'Marcelo', 'First', 'ARG'),
(224, 'Roberto', 'First', 'ARG'),
(225, 'Mario', 'First', 'ARG'),
(226, 'Gonzalez', 'Last', 'ARG'),
(227, 'Rodriguez', 'Last', 'ARG'),
(228, 'Gomez', 'Last', 'ARG'),
(229, 'Fernandez', 'Last', 'ARG'),
(230, 'Lopez', 'Last', 'ARG'),
(231, 'Diaz', 'Last', 'ARG'),
(232, 'Martinez', 'Last', 'ARG'),
(233, 'Perez', 'Last', 'ARG'),
(234, 'Garcia', 'Last', 'ARG'),
(235, 'Sanchez', 'Last', 'ARG'),
(236, 'Romero', 'Last', 'ARG'),
(237, 'Sosa', 'Last', 'ARG'),
(238, 'Alvarez', 'Last', 'ARG'),
(239, 'Torres', 'Last', 'ARG'),
(240, 'Ruiz', 'Last', 'ARG');

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('Transfer','Medical','Finance','Match','System') NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `player_core`
--

CREATE TABLE `player_core` (
  `player_id` int(11) NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `academy_id` int(11) DEFAULT NULL,
  `country_code` varchar(3) NOT NULL,
  `name` varchar(255) NOT NULL,
  `age` int(11) NOT NULL,
  `is_GK` tinyint(1) DEFAULT 0,
  `is_youth` tinyint(1) DEFAULT 0,
  `salary_per_season` bigint(20) DEFAULT 10000,
  `current_stamina` tinyint(4) DEFAULT 100,
  `natural_fitness` tinyint(4) DEFAULT 25,
  `morale` tinyint(4) DEFAULT 25,
  `current_form` decimal(3,2) DEFAULT 5.00,
  `injury_days_left` int(11) DEFAULT 0,
  `suspension_matches_left` int(11) DEFAULT 0,
  `is_available` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `player_core`
--

INSERT INTO `player_core` (`player_id`, `team_id`, `academy_id`, `country_code`, `name`, `age`, `is_GK`, `is_youth`, `salary_per_season`, `current_stamina`, `natural_fitness`, `morale`, `current_form`, `injury_days_left`, `suspension_matches_left`, `is_available`) VALUES
(1, 1, NULL, 'ITA', 'Marco Bianchi', 28, 1, 0, 45000, 92, 42, 80, 5.00, 0, 0, NULL),
(2, 1, NULL, 'ROU', 'Andrei Ionescu', 21, 1, 0, 15000, 92, 39, 75, 5.00, 0, 0, NULL),
(3, 1, NULL, 'ARG', 'Mateo Santos', 24, 0, 0, 25000, 92, 21, 90, 5.00, 0, 0, NULL),
(4, 1, NULL, 'BRA', 'Carlos Silva', 29, 0, 0, 55000, 92, 30, 85, 5.00, 0, 0, NULL),
(5, 1, NULL, 'ENG', 'Liam Davies', 22, 0, 0, 30000, 92, 36, 70, 5.00, 0, 0, NULL),
(6, 1, NULL, 'FRA', 'Jean-Luc Moreau', 26, 0, 0, 40000, 92, 42, 88, 5.00, 0, 0, NULL),
(7, 1, NULL, 'ESP', 'Alejandro Ruiz', 25, 0, 0, 35000, 92, 23, 82, 5.00, 0, 0, NULL),
(8, 1, NULL, 'GER', 'Klaus Weber', 31, 0, 0, 60000, 92, 30, 95, 5.00, 0, 0, NULL),
(9, 1, NULL, 'NGA', 'Chidi Oumarou', 20, 0, 0, 12000, 92, 31, 78, 5.00, 0, 0, NULL),
(10, 1, NULL, 'JPN', 'Kenji Sato', 27, 0, 0, 38000, 92, 44, 86, 5.00, 0, 0, NULL),
(11, 1, NULL, 'ROU', 'Mihai Popescu', 23, 0, 0, 18000, 92, 46, 80, 5.00, 0, 0, NULL),
(12, 1, NULL, 'ARG', 'Diego Alvarez', 28, 0, 0, 50000, 92, 21, 89, 5.00, 0, 0, NULL),
(13, 1, NULL, 'BRA', 'Eduardo Costa', 25, 0, 0, 42000, 92, 39, 92, 5.00, 0, 0, NULL),
(14, 1, NULL, 'ENG', 'Harry Smith', 19, 0, 0, 15000, 92, 20, 75, 5.00, 0, 0, NULL),
(15, 1, NULL, 'ESP', 'Pablo Garcia', 24, 0, 0, 33000, 92, 26, 84, 5.00, 0, 0, NULL),
(16, 1, NULL, 'ESP', 'Francisco Alvarez', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(17, 1, NULL, 'ENG', 'Robert Moore', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(18, 1, NULL, 'ARG', 'Mario Torres', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(19, 1, NULL, 'ENG', 'William Garcia', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(20, 1, NULL, 'FRA', 'Nicolas Simon', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(21, 1, NULL, 'ENG', 'Harry Williams', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(22, 1, NULL, 'ITA', 'Tommaso Marino', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(23, 1, NULL, 'ROU', 'Dan Munteanu', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(24, 1, NULL, 'ENG', 'David Rodriguez', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL),
(25, 1, NULL, 'FRA', 'Thierry Martin', 16, 0, 1, 10000, 100, 25, 25, 5.00, 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `player_experience`
--

CREATE TABLE `player_experience` (
  `player_id` int(11) NOT NULL,
  `xp_technical` int(11) DEFAULT 0,
  `xp_physical` int(11) DEFAULT 0,
  `xp_mental` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `player_stats`
--

CREATE TABLE `player_stats` (
  `stats_id` int(11) NOT NULL,
  `player_id` int(11) NOT NULL,
  `season` int(11) NOT NULL,
  `matches_played` int(11) DEFAULT 0,
  `goals` int(11) DEFAULT 0,
  `assists` int(11) DEFAULT 0,
  `yellow_cards` int(11) DEFAULT 0,
  `red_cards` int(11) DEFAULT 0,
  `average_rating` decimal(3,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sponsorship_deals`
--

CREATE TABLE `sponsorship_deals` (
  `deal_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `income_cat_id` int(11) NOT NULL,
  `sponsor_name` varchar(255) NOT NULL,
  `deal_type` enum('Jersey','Stadium','Image') NOT NULL,
  `annual_value` bigint(20) NOT NULL,
  `contract_years` int(11) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team`
--

CREATE TABLE `team` (
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `team_name` varchar(255) NOT NULL,
  `budget` bigint(20) DEFAULT 1000000,
  `reputation` int(11) DEFAULT 100,
  `stadium_capacity` int(11) DEFAULT 10000,
  `youth_maintenance_cost` int(11) DEFAULT 5000,
  `manager_level` int(11) DEFAULT 1,
  `current_instance_id` int(11) DEFAULT NULL,
  `is_bot` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team`
--

INSERT INTO `team` (`team_id`, `user_id`, `team_name`, `budget`, `reputation`, `stadium_capacity`, `youth_maintenance_cost`, `manager_level`, `current_instance_id`, `is_bot`) VALUES
(1, 1, 'Cosmopolitan FC', 1000000, 100, 10000, 5000, 1, 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `training_settings`
--

CREATE TABLE `training_settings` (
  `team_id` int(11) NOT NULL,
  `focus_area` enum('Shooting','Passing','Defending','Physical','Mental','Balanced') DEFAULT 'Balanced',
  `intensity` enum('Low','Medium','High') DEFAULT 'Medium'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `training_settings`
--

INSERT INTO `training_settings` (`team_id`, `focus_area`, `intensity`) VALUES
(1, 'Shooting', 'High');

-- --------------------------------------------------------

--
-- Table structure for table `transferlist`
--

CREATE TABLE `transferlist` (
  `transfer_id` int(11) NOT NULL,
  `player_id` int(11) NOT NULL,
  `from_team_id` int(11) NOT NULL,
  `start_price` bigint(20) NOT NULL,
  `current_bid` bigint(20) DEFAULT 0,
  `top_bidder_team_id` int(11) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `status` enum('Active','Sold','Expired') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_history`
--

CREATE TABLE `transfer_history` (
  `history_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `player_id` int(11) NOT NULL,
  `from_team_id` int(11) NOT NULL,
  `to_team_id` int(11) NOT NULL,
  `transfer_fee` bigint(20) NOT NULL,
  `transfer_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `mail` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`user_id`, `user_name`, `mail`, `password`, `created_at`) VALUES
(1, 'Manager_Test', 'dof@test.com', 'parola123', '2026-03-21 16:33:52');

-- --------------------------------------------------------

--
-- Table structure for table `youth_staff`
--

CREATE TABLE `youth_staff` (
  `junior_id` int(11) NOT NULL,
  `academy_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `working_with_youngsters` tinyint(4) DEFAULT 25,
  `judging_potential` tinyint(4) DEFAULT 25
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academie`
--
ALTER TABLE `academie`
  ADD PRIMARY KEY (`academy_id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `attributes_goalkeeper`
--
ALTER TABLE `attributes_goalkeeper`
  ADD PRIMARY KEY (`player_id`);

--
-- Indexes for table `attributes_hidden`
--
ALTER TABLE `attributes_hidden`
  ADD PRIMARY KEY (`player_id`);

--
-- Indexes for table `attributes_outfield`
--
ALTER TABLE `attributes_outfield`
  ADD PRIMARY KEY (`player_id`);

--
-- Indexes for table `bid`
--
ALTER TABLE `bid`
  ADD PRIMARY KEY (`bid_id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `bidding_team_id` (`bidding_team_id`);

--
-- Indexes for table `club_facilities`
--
ALTER TABLE `club_facilities`
  ADD PRIMARY KEY (`team_id`);

--
-- Indexes for table `countrycoefficient`
--
ALTER TABLE `countrycoefficient`
  ADD PRIMARY KEY (`country_code`);

--
-- Indexes for table `expenditure_categories`
--
ALTER TABLE `expenditure_categories`
  ADD PRIMARY KEY (`exp_cat_id`);

--
-- Indexes for table `financial_ledger`
--
ALTER TABLE `financial_ledger`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `income_cat_id` (`income_cat_id`),
  ADD KEY `exp_cat_id` (`exp_cat_id`);

--
-- Indexes for table `formations`
--
ALTER TABLE `formations`
  ADD PRIMARY KEY (`formation_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `game_settings`
--
ALTER TABLE `game_settings`
  ADD PRIMARY KEY (`setting_id`);

--
-- Indexes for table `income_categories`
--
ALTER TABLE `income_categories`
  ADD PRIMARY KEY (`income_cat_id`);

--
-- Indexes for table `league_instances`
--
ALTER TABLE `league_instances`
  ADD PRIMARY KEY (`instance_id`),
  ADD KEY `tier_level` (`tier_level`);

--
-- Indexes for table `league_standings`
--
ALTER TABLE `league_standings`
  ADD PRIMARY KEY (`instance_id`,`team_id`),
  ADD KEY `team_id` (`team_id`);

--
-- Indexes for table `league_tiers`
--
ALTER TABLE `league_tiers`
  ADD PRIMARY KEY (`tier_level`);

--
-- Indexes for table `manager`
--
ALTER TABLE `manager`
  ADD PRIMARY KEY (`manager_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `managers`
--
ALTER TABLE `managers`
  ADD PRIMARY KEY (`manager_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `preferred_formation_id` (`preferred_formation_id`);

--
-- Indexes for table `match_fixture`
--
ALTER TABLE `match_fixture`
  ADD PRIMARY KEY (`match_id`),
  ADD KEY `instance_id` (`instance_id`),
  ADD KEY `home_team_id` (`home_team_id`),
  ADD KEY `away_team_id` (`away_team_id`);

--
-- Indexes for table `name_dictionary`
--
ALTER TABLE `name_dictionary`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_random_search` (`country_code`,`name_type`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `player_core`
--
ALTER TABLE `player_core`
  ADD PRIMARY KEY (`player_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `academy_id` (`academy_id`),
  ADD KEY `country_code` (`country_code`);

--
-- Indexes for table `player_experience`
--
ALTER TABLE `player_experience`
  ADD PRIMARY KEY (`player_id`);

--
-- Indexes for table `player_stats`
--
ALTER TABLE `player_stats`
  ADD PRIMARY KEY (`stats_id`),
  ADD KEY `player_id` (`player_id`);

--
-- Indexes for table `sponsorship_deals`
--
ALTER TABLE `sponsorship_deals`
  ADD PRIMARY KEY (`deal_id`),
  ADD KEY `team_id` (`team_id`),
  ADD KEY `income_cat_id` (`income_cat_id`);

--
-- Indexes for table `team`
--
ALTER TABLE `team`
  ADD PRIMARY KEY (`team_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `current_instance_id` (`current_instance_id`);

--
-- Indexes for table `training_settings`
--
ALTER TABLE `training_settings`
  ADD PRIMARY KEY (`team_id`);

--
-- Indexes for table `transferlist`
--
ALTER TABLE `transferlist`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `player_id` (`player_id`),
  ADD KEY `from_team_id` (`from_team_id`);

--
-- Indexes for table `transfer_history`
--
ALTER TABLE `transfer_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `player_id` (`player_id`),
  ADD KEY `from_team_id` (`from_team_id`),
  ADD KEY `to_team_id` (`to_team_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `mail` (`mail`);

--
-- Indexes for table `youth_staff`
--
ALTER TABLE `youth_staff`
  ADD PRIMARY KEY (`junior_id`),
  ADD KEY `academy_id` (`academy_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academie`
--
ALTER TABLE `academie`
  MODIFY `academy_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bid`
--
ALTER TABLE `bid`
  MODIFY `bid_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenditure_categories`
--
ALTER TABLE `expenditure_categories`
  MODIFY `exp_cat_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_ledger`
--
ALTER TABLE `financial_ledger`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `formations`
--
ALTER TABLE `formations`
  MODIFY `formation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `income_categories`
--
ALTER TABLE `income_categories`
  MODIFY `income_cat_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `league_instances`
--
ALTER TABLE `league_instances`
  MODIFY `instance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `manager`
--
ALTER TABLE `manager`
  MODIFY `manager_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `managers`
--
ALTER TABLE `managers`
  MODIFY `manager_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `match_fixture`
--
ALTER TABLE `match_fixture`
  MODIFY `match_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `name_dictionary`
--
ALTER TABLE `name_dictionary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=241;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `player_core`
--
ALTER TABLE `player_core`
  MODIFY `player_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `player_stats`
--
ALTER TABLE `player_stats`
  MODIFY `stats_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sponsorship_deals`
--
ALTER TABLE `sponsorship_deals`
  MODIFY `deal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team`
--
ALTER TABLE `team`
  MODIFY `team_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transferlist`
--
ALTER TABLE `transferlist`
  MODIFY `transfer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfer_history`
--
ALTER TABLE `transfer_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `youth_staff`
--
ALTER TABLE `youth_staff`
  MODIFY `junior_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academie`
--
ALTER TABLE `academie`
  ADD CONSTRAINT `academie_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`) ON DELETE CASCADE;

--
-- Constraints for table `attributes_goalkeeper`
--
ALTER TABLE `attributes_goalkeeper`
  ADD CONSTRAINT `attributes_goalkeeper_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`) ON DELETE CASCADE;

--
-- Constraints for table `attributes_hidden`
--
ALTER TABLE `attributes_hidden`
  ADD CONSTRAINT `attributes_hidden_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`) ON DELETE CASCADE;

--
-- Constraints for table `attributes_outfield`
--
ALTER TABLE `attributes_outfield`
  ADD CONSTRAINT `attributes_outfield_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`) ON DELETE CASCADE;

--
-- Constraints for table `bid`
--
ALTER TABLE `bid`
  ADD CONSTRAINT `bid_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `transferlist` (`transfer_id`),
  ADD CONSTRAINT `bid_ibfk_2` FOREIGN KEY (`bidding_team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `club_facilities`
--
ALTER TABLE `club_facilities`
  ADD CONSTRAINT `club_facilities_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`) ON DELETE CASCADE;

--
-- Constraints for table `financial_ledger`
--
ALTER TABLE `financial_ledger`
  ADD CONSTRAINT `financial_ledger_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `financial_ledger_ibfk_2` FOREIGN KEY (`income_cat_id`) REFERENCES `income_categories` (`income_cat_id`),
  ADD CONSTRAINT `financial_ledger_ibfk_3` FOREIGN KEY (`exp_cat_id`) REFERENCES `expenditure_categories` (`exp_cat_id`);

--
-- Constraints for table `league_instances`
--
ALTER TABLE `league_instances`
  ADD CONSTRAINT `league_instances_ibfk_1` FOREIGN KEY (`tier_level`) REFERENCES `league_tiers` (`tier_level`);

--
-- Constraints for table `league_standings`
--
ALTER TABLE `league_standings`
  ADD CONSTRAINT `league_standings_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `league_instances` (`instance_id`),
  ADD CONSTRAINT `league_standings_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `manager`
--
ALTER TABLE `manager`
  ADD CONSTRAINT `manager_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `manager_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `managers`
--
ALTER TABLE `managers`
  ADD CONSTRAINT `managers_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `managers_ibfk_2` FOREIGN KEY (`preferred_formation_id`) REFERENCES `formations` (`formation_id`);

--
-- Constraints for table `match_fixture`
--
ALTER TABLE `match_fixture`
  ADD CONSTRAINT `match_fixture_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `league_instances` (`instance_id`),
  ADD CONSTRAINT `match_fixture_ibfk_2` FOREIGN KEY (`home_team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `match_fixture_ibfk_3` FOREIGN KEY (`away_team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `player_core`
--
ALTER TABLE `player_core`
  ADD CONSTRAINT `player_core_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `player_core_ibfk_2` FOREIGN KEY (`academy_id`) REFERENCES `academie` (`academy_id`),
  ADD CONSTRAINT `player_core_ibfk_3` FOREIGN KEY (`country_code`) REFERENCES `countrycoefficient` (`country_code`);

--
-- Constraints for table `player_experience`
--
ALTER TABLE `player_experience`
  ADD CONSTRAINT `player_experience_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`) ON DELETE CASCADE;

--
-- Constraints for table `player_stats`
--
ALTER TABLE `player_stats`
  ADD CONSTRAINT `player_stats_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`) ON DELETE CASCADE;

--
-- Constraints for table `sponsorship_deals`
--
ALTER TABLE `sponsorship_deals`
  ADD CONSTRAINT `sponsorship_deals_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `sponsorship_deals_ibfk_2` FOREIGN KEY (`income_cat_id`) REFERENCES `income_categories` (`income_cat_id`);

--
-- Constraints for table `team`
--
ALTER TABLE `team`
  ADD CONSTRAINT `team_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `team_ibfk_2` FOREIGN KEY (`current_instance_id`) REFERENCES `league_instances` (`instance_id`);

--
-- Constraints for table `training_settings`
--
ALTER TABLE `training_settings`
  ADD CONSTRAINT `training_settings_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `transferlist`
--
ALTER TABLE `transferlist`
  ADD CONSTRAINT `transferlist_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`),
  ADD CONSTRAINT `transferlist_ibfk_2` FOREIGN KEY (`from_team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `transfer_history`
--
ALTER TABLE `transfer_history`
  ADD CONSTRAINT `transfer_history_ibfk_1` FOREIGN KEY (`player_id`) REFERENCES `player_core` (`player_id`),
  ADD CONSTRAINT `transfer_history_ibfk_2` FOREIGN KEY (`from_team_id`) REFERENCES `team` (`team_id`),
  ADD CONSTRAINT `transfer_history_ibfk_3` FOREIGN KEY (`to_team_id`) REFERENCES `team` (`team_id`);

--
-- Constraints for table `youth_staff`
--
ALTER TABLE `youth_staff`
  ADD CONSTRAINT `youth_staff_ibfk_1` FOREIGN KEY (`academy_id`) REFERENCES `academie` (`academy_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
