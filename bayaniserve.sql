-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 24, 2026 at 11:07 AM
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
-- Database: `bayaniserve`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `quantity_before` int(11) DEFAULT NULL,
  `quantity_after` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('bhw','city_health_officer','super_admin') NOT NULL DEFAULT 'bhw',
  `station_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `full_name`, `role`, `station_id`, `created_by`, `created_at`, `is_active`) VALUES
(4, 'hilamonan', '$2y$10$HEtzJ8zK1abvc6YHZ/twZuSbU/H8PznS2dwCNqbvRC3w9DUx5ayHC', 'Hilamonan Midwife', 'bhw', 1, NULL, '2026-06-19 14:42:44', 1),
(5, 'cityhealth', '$2y$10$eT6vOjoJuhvGqL/VlKm5OOGJUmo4/OAiXLhAQ3DOLrUB44cnbs.jq', 'City Health Office', 'super_admin', NULL, NULL, '2026-06-21 05:52:51', 1);

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target_station_id` int(11) DEFAULT NULL,
  `sent_as_sms` tinyint(1) NOT NULL DEFAULT 0,
  `posted_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `message`, `target_station_id`, `sent_as_sms`, `posted_by`, `created_at`) VALUES
(1, 'Libreng Bakuna', 'Libreng Bakuna on July 2, 2026 at the health center', 1, 1, 4, '2026-06-21 08:43:15');

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `name`) VALUES
(1, 'Hilamonan'),
(2, 'Camugao'),
(3, 'Carol-an');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `sender` enum('resident','ai') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `session_id`, `sender`, `message`, `created_at`) VALUES
(1, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'may ara paracetamol?', '2026-06-19 00:56:42'),
(2, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sorry, walang nareceive na response.', '2026-06-19 00:56:42'),
(3, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'may ara ba paracetamol?', '2026-06-19 02:33:13'),
(4, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sorry, walang nareceive na response.', '2026-06-19 02:33:14'),
(5, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'may ara ba paracetamol subong?', '2026-06-19 03:39:58'),
(6, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'AI model is currently unavailable. Please try again.', '2026-06-19 03:40:58'),
(7, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'may ara ba paracetamol subong?', '2026-06-19 03:46:12'),
(8, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'AI model is currently unavailable. Please try again.', '2026-06-19 03:46:14'),
(9, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 07:47:07'),
(10, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'AI model is currently unavailable. Please try again.', '2026-06-19 07:48:08'),
(11, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 07:49:28'),
(12, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'AI model is currently unavailable. Please try again.', '2026-06-19 07:50:28'),
(13, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 08:01:09'),
(14, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'AI model is currently unavailable. Please try again.', '2026-06-19 08:02:09'),
(15, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 08:05:16'),
(16, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Hindi makakonekta sa AI model. Siguraduhin na tumatakbo ang llama.cpp server sa port 8080.', '2026-06-19 08:05:48'),
(17, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 08:09:03'),
(18, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Kumusta ka? Ano gamot ka magtatanong? Available kami ng mga gamot sa Hilamonan. Mag-email ka lang sa amin sa [bayaniserve@negrosocc.gov.ph](mailto:bayaniserve@negrosocc.gov.ph) para sa listahan ng available na gamot o mag-reserve ka ng gamot para sa iyo. Mabuti ka na sa lahat!', '2026-06-19 08:10:03'),
(19, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'gina muno ko may ara bala subong bulong nga paracetamol sa brgy?', '2026-06-19 08:14:32'),
(20, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Gali mo ang medicine availability subong sa bala subong bulong. May ara ba paracetamol? (Do you want to check the medicine availability for the barangay? Is paracetamol available?)', '2026-06-19 08:15:15'),
(21, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'oo, may ara ba subong sila paracetamol sa hilamonan?', '2026-06-19 08:16:29'),
(22, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Oo, may paracetamol na available sa aton hilamonan. Pwede ba nimo magrequest para mareserve?', '2026-06-19 08:17:10'),
(23, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'oo, may ara ba subong sila paracetamol sa hilamonan?', '2026-06-19 08:24:51'),
(24, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Ha, wala gyud sa atoang hilamonan ang paracetamol. May pag-avail ba kaba?', '2026-06-19 08:25:32'),
(25, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'oo, may ara ba subong sila paracetamol sa hilamonan?', '2026-06-19 08:28:02'),
(26, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Oo, may paracetamol sa hilamonan. Mayroon tayong 50 na tablet sa aton stock. Ano ang iboborrow mo?', '2026-06-19 08:28:37'),
(27, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'wala paman stock sang biskan ano nga bulong pag check ko sang availability sang mga bulong paano mo nahambal nga may ara?', '2026-06-19 08:30:28'),
(28, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'wala paman stock sang biskan ano nga bulong pag check ko sang availability sang mga bulong paano mo nahambal nga may ara?', '2026-06-19 08:33:47'),
(29, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Kahangud, mabuti lang na nindot ang pag-check sa availability sang mga bulong. Pwede mo pag-check sa system sang BayaniServe. Galing sa sistema, wala paman stock sang biskan sa barangay health station mo. Pwede mo pag-reserve sang biskan kung mo gusto. O pwede mo gasto na lang sa drugstore. Mahal lang ang pag-reserve kung wala sa stock.', '2026-06-19 08:34:51'),
(30, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Ano ang available na gamot sa Hilamonan?', '2026-06-19 08:50:41'),
(31, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Nagkaroon ng error ang AI model (HTTP 503). Subukan ulit.', '2026-06-19 08:50:41'),
(32, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan nga pwede pangayuon?', '2026-06-19 08:51:47'),
(33, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan nga pwede pangayuon?', '2026-06-19 08:54:25'),
(34, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wala pa gyud ko\'y stock data sa sistema. Kung moapil ka, pwede mo pangdala ang iyong mga kailangan sa hilamonan.', '2026-06-19 08:56:02'),
(35, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan nga pwede pangayuon?', '2026-06-19 08:58:25'),
(36, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan?', '2026-06-19 11:53:22'),
(37, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano ang bulong nga ara subong?', '2026-06-19 12:42:47'),
(38, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wa pa kami sa mga gamot, kay wala pa kami nag-anunsyo sa aton inventaryo. Pero kung gusto mo mag-reserve o mag-request, pwede ka lang mag-type \"reserve [gamot]\" or \"request [gamot]\".', '2026-06-19 12:43:54'),
(39, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko mag-reserve sang Cetirizine 10mg', '2026-06-19 14:46:13'),
(40, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko mag-reserve sang Cetirizine 10mg', '2026-06-19 14:52:16'),
(41, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko mag-reserve sang Cetirizine 10mg', '2026-06-19 14:54:50'),
(42, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Available ang Cetirizine 10mg sa Hilamonan, may 200 unit. Available usab sa Camugao, may 200 unit. Kinsa man lugar, pwede mo na lang mag-reserve.\n\nType \"reserve [medicine]\" para makahabol ang reservation mo.', '2026-06-19 14:55:45'),
(43, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'sa hilamonan tani', '2026-06-19 14:56:34'),
(44, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Hilamonan, may available nga medicine:\n\n* Amlodipine 5mg: 95 units\n* Amoxicillin 500mg: 24 units\n* Cetirizine 10mg: 200 units\n* Metformin 500mg: 80 units\n* Paracetamol 500mg: 300 units\n\nKumusta ka magpahalubag sa among medicine? Mag-reserve o mag-request ba ka sang among available nga medicine?', '2026-06-19 14:57:58'),
(45, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'wala paman stocks sang biskan ano nga bulong sa camugao paano mo nahambal nga may ara 200 unit?', '2026-06-19 14:58:36'),
(46, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'wala paman stocks sang biskan ano nga bulong sa camugao paano mo nahambal nga may ara 200 unit?', '2026-06-19 15:01:47'),
(47, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'gusto ko tani magpa reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-19 15:15:02'),
(48, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'gusto ko tani magpa reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-19 15:16:26'),
(49, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-20 09:22:41'),
(50, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-20 09:23:58'),
(51, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Cetirizine 10mg sa Hilamonan', '2026-06-20 09:31:39'),
(52, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Cetirizine 10mg sa Hilamonan', '2026-06-20 09:33:42'),
(53, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Cetirizine 10mg sa Hilamonan', '2026-06-20 09:34:17'),
(54, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Cetirizine 10mg sa Hilamonan', '2026-06-20 09:38:12'),
(55, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Cetirizine 10mg sa Hilamonan', '2026-06-20 09:56:08'),
(56, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-20 10:36:50'),
(57, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Paracetamol 500mg sa Hilamonan', '2026-06-21 03:38:06'),
(58, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Paracetamol sa hilamonan', '2026-06-21 04:28:48'),
(59, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpa-reserve sang Metformin 500mg sa Hilamonan', '2026-06-21 05:17:33'),
(60, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wala sing stock sang Metformin 500mg sa Hilamonan. Pero kung gusto mo magpa-reserve, pwede ka mag-type \"reserve [medicine]\".', '2026-06-21 05:21:28'),
(61, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'paano mo nahambal nga wala stock? bag-o lang ko check may ara lang gid stocks', '2026-06-21 05:23:22'),
(62, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wala sing stock sa Inapoy para sa Paracetamol 500mg, kay ubos ang stock. Pero may available pa gyud sa Camugao ug Hilamonan.\n\nKung mo gusto ka mag-reserve o mag-request, pwede ka mag-type \"reserve [medicine]\" or \"request [medicine]\".', '2026-06-21 05:24:25'),
(63, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko mag-reserve ng Paracetamol 500mg sa Hilamonan', '2026-06-21 05:46:40'),
(64, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wala sing stock sang Paracetamol 500mg sa Camugao, pero may ubos lang ang stock sa Inapoy. Kung gusto mo mag-reserve, pwede ka mag-type \"reserve [medicine]\".', '2026-06-21 05:49:39'),
(65, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'Gusto ko magpareserve sang Paracetamol 500mg sa Hilamonan', '2026-06-21 06:12:05'),
(66, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Wala sing stock sang Paracetamol 500mg sa Camugao, pero may ubos lang ang stock sa Inapoy. Kung gusto mo magreserba, pwede ka magtype \"reserve [medicine]\".', '2026-06-21 06:14:36'),
(67, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ngaa sa camugao eh sa hilamonan ko gani mapareserve', '2026-06-21 06:17:35'),
(68, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Camugao, wala sing stock sang Cetirizine 10mg. Pero sa Hilamonan, available ang Cetirizine 10mg. Kung mo gusto mag-reserve, pwede ka mag-type \"reserve [medicine]\".', '2026-06-21 06:18:21'),
(69, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga mga bulong ang ara subong sa brgy hilamonan?', '2026-06-21 06:22:26'),
(70, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Brgy. Hilamonan, may available na mga bulong ang ato:\n\n- Amlodipine 5mg: Available (95 units)\n- Amoxicillin 500mg: Available (24 units)\n- Cetirizine 10mg: Available (200 units)\n- Metformin 500mg: Available (80 units)\n- Paracetamol 500mg: Available (300 units)\n\nKumusta ang pagtubos sa Hilamonan?', '2026-06-21 06:25:02'),
(71, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'sa camugao ya?', '2026-06-21 06:26:00'),
(72, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Available ang Cetirizine 10mg sa Camugao, mayroon pang 200 units. May available din ang Paracetamol 500mg, mayroon pang 80 units. Ang Camugao ang inyong pinili ba?', '2026-06-21 06:26:42'),
(73, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga mga bulong ang ara sa hilamonan?', '2026-06-21 06:33:18'),
(74, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan?', '2026-06-21 06:49:48'),
(75, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan?', '2026-06-21 08:45:07'),
(76, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan?', '2026-06-21 09:05:08'),
(77, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'ano nga bulong ang ara subong sa hilamonan?', '2026-06-21 09:10:43'),
(78, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Hilamonan, may ara pa kita natabilin nga mga bulong. Ara subong, may ari kita diri 95 units sang Amlodipine 5mg kag 24 units sang Amoxicillin. Gusto mo ipareserve ini para sa imo?', '2026-06-21 09:11:19'),
(79, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'oo tani paano magpareserve?', '2026-06-21 09:11:52'),
(80, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Brgy. Hilamonan, may ara pa kita natabilin nga 95 units sang Amlodipine 5mg kag 24 units sang Amoxicillin. Gusto mo ipareserve ini para sa imo?', '2026-06-21 09:12:20'),
(81, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'pa reserve ko tani isa ka amlodipine', '2026-06-21 09:13:21'),
(82, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Inapoy, may ara pa kita natabilin nga Amlodipine 5mg. Gusto mo ipareserve ini para sa imo?', '2026-06-21 09:13:50'),
(83, '6c18c270238e35d1d1083e841b658c2d', 'resident', 'as brgy hilamonan ko mapareserve', '2026-06-21 09:14:23'),
(84, '6c18c270238e35d1d1083e841b658c2d', 'ai', 'Sa Brgy. Hilamonan, may ara pa kita natabilin nga 95 units sang Amlodipine 5mg kag 24 units sang Amoxicillin. Gusto mo ipareserve ini para sa imo?', '2026-06-21 09:14:52');

-- --------------------------------------------------------

--
-- Table structure for table `dispensing_logs`
--

CREATE TABLE `dispensing_logs` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity_dispensed` int(11) NOT NULL,
  `dispensed_by` int(11) NOT NULL,
  `dispensed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dispensing_logs`
--

INSERT INTO `dispensing_logs` (`id`, `resident_id`, `medicine_id`, `quantity_dispensed`, `dispensed_by`, `dispensed_at`) VALUES
(1, 33, 2, 1, 4, '2026-06-24 08:38:10');

-- --------------------------------------------------------

--
-- Table structure for table `emergency_distributions`
--

CREATE TABLE `emergency_distributions` (
  `id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `household_rep` varchar(150) NOT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `distributed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `emergency_mode`
--

CREATE TABLE `emergency_mode` (
  `id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `label` varchar(150) NOT NULL DEFAULT 'Emergency Mode',
  `description` text DEFAULT NULL,
  `activated_by` int(11) DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `deactivated_by` int(11) DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `per_hh_limit` int(11) NOT NULL DEFAULT 5,
  `bypass_approval` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `emergency_mode`
--

INSERT INTO `emergency_mode` (`id`, `is_active`, `label`, `description`, `activated_by`, `activated_at`, `deactivated_by`, `deactivated_at`, `per_hh_limit`, `bypass_approval`) VALUES
(1, 0, 'Emergency Mode', '', 5, '2026-06-24 16:23:46', 5, '2026-06-24 17:00:33', 5, 1);

-- --------------------------------------------------------

--
-- Table structure for table `health_stations`
--

CREATE TABLE `health_stations` (
  `id` int(11) NOT NULL,
  `barangay_name` varchar(100) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `health_stations`
--

INSERT INTO `health_stations` (`id`, `barangay_name`, `latitude`, `longitude`, `contact_number`, `created_at`) VALUES
(1, 'Hilamonan', NULL, NULL, '034-XXX-0001', '2026-06-19 00:51:41'),
(2, 'Camugao', NULL, NULL, '034-XXX-0002', '2026-06-19 00:51:41'),
(3, 'Inapoy', NULL, NULL, '034-XXX-0003', '2026-06-19 00:51:41');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('in_stock','low_stock','out_of_stock') GENERATED ALWAYS AS (case when `quantity` = 0 then 'out_of_stock' when `quantity` <= 15 then 'low_stock' else 'in_stock' end) STORED,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `station_id`, `medicine_id`, `quantity`, `last_updated`) VALUES
(1, 1, 1, 300, '2026-06-19 14:45:19'),
(2, 1, 2, 23, '2026-06-24 08:38:10'),
(3, 2, 1, 80, '2026-06-19 12:49:19'),
(4, 2, 4, 200, '2026-06-19 12:49:19'),
(5, 3, 1, 5, '2026-06-19 12:49:19'),
(6, 3, 6, 95, '2026-06-19 12:49:19'),
(7, 3, 2, 60, '2026-06-19 12:49:19'),
(10, 1, 10, 80, '2026-06-19 14:45:19'),
(11, 1, 4, 200, '2026-06-19 14:45:19'),
(12, 1, 6, 95, '2026-06-19 14:45:19');

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicines`
--

INSERT INTO `medicines` (`id`, `name`, `category`, `description`, `created_at`) VALUES
(1, 'Paracetamol 500mg', 'Analgesic', NULL, '2026-06-19 12:49:19'),
(2, 'Amoxicillin 500mg', 'Antibiotic', NULL, '2026-06-19 12:49:19'),
(4, 'Cetirizine 10mg', 'Antihistamine', NULL, '2026-06-19 12:49:19'),
(6, 'Amlodipine 5mg', 'Antihypertensive', NULL, '2026-06-19 12:49:19'),
(10, 'Metformin 500mg', 'Antidiabetic', NULL, '2026-06-19 14:45:19');

-- --------------------------------------------------------

--
-- Table structure for table `medicine_requests`
--

CREATE TABLE `medicine_requests` (
  `id` int(11) NOT NULL,
  `resident_name` varchar(150) NOT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_name` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','fulfilled','dismissed') NOT NULL DEFAULT 'pending',
  `source` enum('chatbot','sms') NOT NULL DEFAULT 'chatbot',
  `raw_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `handled_by` int(11) DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `review_status` enum('pending_local','pushed_to_city','resolved_locally','rejected_locally') DEFAULT 'pending_local',
  `pushed_requisition_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `monthly_inventory_snapshots`
--

CREATE TABLE `monthly_inventory_snapshots` (
  `id` int(11) NOT NULL,
  `snapshot_month` char(7) NOT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `opening_quantity` int(11) NOT NULL DEFAULT 0,
  `closing_quantity` int(11) NOT NULL DEFAULT 0,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `quantity_distributed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `requisitions`
--

CREATE TABLE `requisitions` (
  `id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_id` int(11) DEFAULT NULL,
  `requested_qty` int(11) NOT NULL,
  `approved_qty` int(11) DEFAULT NULL,
  `status` enum('pending','approved','partial','rejected','delivered') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `submitted_by` int(11) NOT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `delivery_confirmed_by` int(11) DEFAULT NULL,
  `delivery_confirmed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_medicine_name` varchar(150) DEFAULT NULL,
  `reason_description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `resident_name` varchar(150) NOT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `station_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `pickup_date` date DEFAULT NULL,
  `status` enum('pending','approved','declined','completed') NOT NULL DEFAULT 'pending',
  `source` enum('chatbot','sms') NOT NULL DEFAULT 'chatbot',
  `raw_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `handled_by` int(11) DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `resident_name`, `mobile_number`, `station_id`, `medicine_id`, `pickup_date`, `status`, `source`, `raw_message`, `created_at`, `handled_by`, `handled_at`) VALUES
(1, 'Unknown (via chat)', NULL, 1, 1, NULL, 'pending', 'chatbot', 'Gusto ko mag-reserve ng Paracetamol 500mg sa Hilamonan', '2026-06-21 05:51:37', NULL, NULL),
(2, 'Unknown (via chat)', NULL, 1, 1, NULL, 'pending', 'chatbot', 'Gusto ko magpareserve sang Paracetamol 500mg sa Hilamonan', '2026-06-21 06:15:11', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `purok` varchar(50) DEFAULT NULL,
  `mobile_number` varchar(20) NOT NULL,
  `station_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`id`, `full_name`, `purok`, `mobile_number`, `station_id`, `created_at`) VALUES
(31, 'Juan Dela Cruz', NULL, '09171234568', 1, '2026-06-21 08:42:25'),
(32, 'Maria Clara Silang', NULL, '09189876543', 1, '2026-06-21 08:42:25'),
(33, 'Pedro Penduko', NULL, '09225554433', 1, '2026-06-21 08:42:25'),
(34, 'Elena Rivera', NULL, '09057778899', 1, '2026-06-21 08:42:25'),
(35, 'Santi Santillan', NULL, '09391112233', 1, '2026-06-21 08:42:25');

-- --------------------------------------------------------

--
-- Table structure for table `sms_log`
--

CREATE TABLE `sms_log` (
  `id` int(11) NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `mobile_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed','received') NOT NULL DEFAULT 'sent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_outbox`
--

CREATE TABLE `sms_outbox` (
  `id` int(11) NOT NULL,
  `station_id` int(11) DEFAULT NULL,
  `recipient_mobile` varchar(20) NOT NULL,
  `message_text` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `type` enum('Reservation','Request') NOT NULL,
  `resident_name` varchar(255) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `medicine_name` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `station_id` (`station_id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_admin_station` (`station_id`),
  ADD KEY `fk_admins_created_by` (`created_by`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `target_station_id` (`target_station_id`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dispensing_logs`
--
ALTER TABLE `dispensing_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `medicine_id` (`medicine_id`);

--
-- Indexes for table `emergency_distributions`
--
ALTER TABLE `emergency_distributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `station_id` (`station_id`),
  ADD KEY `medicine_id` (`medicine_id`),
  ADD KEY `distributed_by` (`distributed_by`);

--
-- Indexes for table `emergency_mode`
--
ALTER TABLE `emergency_mode`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activated_by` (`activated_by`),
  ADD KEY `deactivated_by` (`deactivated_by`);

--
-- Indexes for table `health_stations`
--
ALTER TABLE `health_stations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_station_medicine` (`station_id`,`medicine_id`),
  ADD KEY `medicine_id` (`medicine_id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_medicine_name` (`name`);

--
-- Indexes for table `medicine_requests`
--
ALTER TABLE `medicine_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `station_id` (`station_id`),
  ADD KEY `handled_by` (`handled_by`),
  ADD KEY `fk_requests_pushed_req` (`pushed_requisition_id`);

--
-- Indexes for table `monthly_inventory_snapshots`
--
ALTER TABLE `monthly_inventory_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_station_med_month` (`snapshot_month`,`station_id`,`medicine_id`),
  ADD KEY `station_id` (`station_id`),
  ADD KEY `medicine_id` (`medicine_id`);

--
-- Indexes for table `requisitions`
--
ALTER TABLE `requisitions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `station_id` (`station_id`),
  ADD KEY `medicine_id` (`medicine_id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `decided_by` (`decided_by`),
  ADD KEY `delivery_confirmed_by` (`delivery_confirmed_by`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `station_id` (`station_id`),
  ADD KEY `medicine_id` (`medicine_id`),
  ADD KEY `handled_by` (`handled_by`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile_number` (`mobile_number`),
  ADD UNIQUE KEY `uniq_mobile_number` (`mobile_number`),
  ADD KEY `station_id` (`station_id`);

--
-- Indexes for table `sms_log`
--
ALTER TABLE `sms_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  ADD PRIMARY KEY (`id`),
  ADD KEY `station_id` (`station_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `dispensing_logs`
--
ALTER TABLE `dispensing_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `emergency_distributions`
--
ALTER TABLE `emergency_distributions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `emergency_mode`
--
ALTER TABLE `emergency_mode`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `health_stations`
--
ALTER TABLE `health_stations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `medicine_requests`
--
ALTER TABLE `medicine_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `monthly_inventory_snapshots`
--
ALTER TABLE `monthly_inventory_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requisitions`
--
ALTER TABLE `requisitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `sms_log`
--
ALTER TABLE `sms_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `activity_log_ibfk_2` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_admin_station` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_admins_created_by` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`target_station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dispensing_logs`
--
ALTER TABLE `dispensing_logs`
  ADD CONSTRAINT `dispensing_logs_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`),
  ADD CONSTRAINT `dispensing_logs_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `inventory` (`id`);

--
-- Constraints for table `emergency_distributions`
--
ALTER TABLE `emergency_distributions`
  ADD CONSTRAINT `ed_by` FOREIGN KEY (`distributed_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ed_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ed_station` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `emergency_mode`
--
ALTER TABLE `emergency_mode`
  ADD CONSTRAINT `em_activated_by` FOREIGN KEY (`activated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `em_deactivated_by` FOREIGN KEY (`deactivated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `medicine_requests`
--
ALTER TABLE `medicine_requests`
  ADD CONSTRAINT `fk_requests_pushed_req` FOREIGN KEY (`pushed_requisition_id`) REFERENCES `requisitions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `medicine_requests_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `medicine_requests_ibfk_2` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `monthly_inventory_snapshots`
--
ALTER TABLE `monthly_inventory_snapshots`
  ADD CONSTRAINT `monthly_inventory_snapshots_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `monthly_inventory_snapshots_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `requisitions`
--
ALTER TABLE `requisitions`
  ADD CONSTRAINT `requisitions_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `requisitions_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `requisitions_ibfk_3` FOREIGN KEY (`submitted_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `requisitions_ibfk_4` FOREIGN KEY (`decided_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `requisitions_ibfk_5` FOREIGN KEY (`delivery_confirmed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_3` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `residents_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  ADD CONSTRAINT `sms_outbox_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `health_stations` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
