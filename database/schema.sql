-- EQAS Neisseria gonorrhoeae - Database schema
-- Compatible con MySQL / MariaDB (InnoDB, utf8mb4)

CREATE DATABASE IF NOT EXISTS eqas_ng CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE eqas_ng;

-- Roles
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Laboratorios / Organizaciones
DROP TABLE IF EXISTS labs;
CREATE TABLE labs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50),
  address VARCHAR(255),
  contact VARCHAR(150),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuarios
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150),
  role_id INT NOT NULL,
  lab_id INT,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_users_lab FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Antibióticos maestro
DROP TABLE IF EXISTS antibiotics;
CREATE TABLE antibiotics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  abbreviation VARCHAR(50),
  atc_code VARCHAR(50),
  description VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Puntos de corte (breakpoints)
DROP TABLE IF EXISTS breakpoints;
CREATE TABLE breakpoints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  antibiotic_id INT NOT NULL,
  standard ENUM('CLSI','EUCAST','LOCAL') NOT NULL,
  version VARCHAR(50) NULL,
  organism VARCHAR(150) DEFAULT 'Neisseria gonorrhoeae',
  method ENUM('disk','mic') NOT NULL,
  unit VARCHAR(30) NOT NULL,
  -- Convención de campos:
  -- Para 'disk' (mm): S si raw >= s_upper; I si raw entre i_lower..i_upper; R si raw <= r_lower
  -- Para 'mic' (µg/mL): S si raw <= s_upper; I si raw entre i_lower..i_upper; R si raw >= r_lower
  s_upper DECIMAL(10,4) DEFAULT NULL,
  i_lower DECIMAL(10,4) DEFAULT NULL,
  i_upper DECIMAL(10,4) DEFAULT NULL,
  r_lower DECIMAL(10,4) DEFAULT NULL,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_breakpoints_antibiotic FOREIGN KEY (antibiotic_id) REFERENCES antibiotics(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Encuestas (Survey)
DROP TABLE IF EXISTS surveys;
CREATE TABLE surveys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  created_by INT,
  scope ENUM('global','lab') DEFAULT 'global',
  lab_id INT,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_surveys_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_surveys_lab FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preguntas
DROP TABLE IF EXISTS survey_questions;
CREATE TABLE survey_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  survey_id INT NOT NULL,
  question_text VARCHAR(1000) NOT NULL,
  question_key VARCHAR(150),
  question_type ENUM('text','select','multiselect','numeric','antibiotic') DEFAULT 'text',
  required TINYINT(1) DEFAULT 0,
  help_text VARCHAR(500),
  display_order INT DEFAULT 0,
  max_length INT DEFAULT NULL,
  antibiotic_id INT DEFAULT NULL,
  CONSTRAINT fk_questions_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_questions_antibiotic FOREIGN KEY (antibiotic_id) REFERENCES antibiotics(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opciones para select/multiselect
DROP TABLE IF EXISTS question_options;
CREATE TABLE question_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  value VARCHAR(200) NOT NULL,
  label VARCHAR(500),
  display_order INT DEFAULT 0,
  CONSTRAINT fk_options_question FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Respuestas / envíos de encuestas
DROP TABLE IF EXISTS responses;
CREATE TABLE responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  survey_id INT NOT NULL,
  user_id INT,
  lab_id INT,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status ENUM('draft','submitted') DEFAULT 'submitted',
  CONSTRAINT fk_responses_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_responses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_responses_lab FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Respuestas individuales por pregunta
DROP TABLE IF EXISTS response_answers;
CREATE TABLE response_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  response_id INT NOT NULL,
  question_id INT NOT NULL,
  option_id INT DEFAULT NULL,
  answer_text TEXT DEFAULT NULL,
  answer_number DECIMAL(10,4) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_answers_response FOREIGN KEY (response_id) REFERENCES responses(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_answers_option FOREIGN KEY (option_id) REFERENCES question_options(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Resultados específicos de antibióticos
DROP TABLE IF EXISTS antibiotic_results;
CREATE TABLE antibiotic_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  response_answer_id INT NOT NULL,
  antibiotic_id INT NOT NULL,
  breakpoint_id INT DEFAULT NULL,
  method ENUM('disk','mic') NOT NULL,
  raw_value DECIMAL(10,4) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  interpretation ENUM('S','I','R','U') DEFAULT 'U',
  calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_abres_answer FOREIGN KEY (response_answer_id) REFERENCES response_answers(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_abres_antibiotic FOREIGN KEY (antibiotic_id) REFERENCES antibiotics(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_abres_breakpoint FOREIGN KEY (breakpoint_id) REFERENCES breakpoints(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auditoría
DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(150),
  object_type VARCHAR(100),
  object_id INT,
  detail TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Índices adicionales
CREATE INDEX idx_users_lab ON users(lab_id);
CREATE INDEX idx_surveys_lab ON surveys(lab_id);
CREATE INDEX idx_responses_lab ON responses(lab_id);
CREATE INDEX idx_breakpoints_ab ON breakpoints(antibiotic_id,standard,method);

-- Datos iniciales mínimos para roles
INSERT INTO roles (name, description) VALUES
 ('super_admin','Control total del sistema'),
 ('admin','Administrador del sistema'),
 ('lab_user','Usuario de laboratorio');

-- Fin del esquema
