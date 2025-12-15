-- Seed: antibióticos y breakpoints de ejemplo (para pruebas)
USE eqas_ng;

-- Antibióticos: Ciprofloxacina, Ceftriaxona, Azitromicina
INSERT INTO antibiotics (name, abbreviation, atc_code, description) VALUES
('Ciprofloxacin','CIP','J01MA02','Ciprofloxacin - example entry'),
('Ceftriaxone','CRO','J01DD04','Ceftriaxone - example entry'),
('Azithromycin','AZM','J01FA10','Azithromycin - example entry');

-- Ejemplo de breakpoints para Ciprofloxacin según CLSI (valores ilustrativos, validar con CLSI oficial antes de usar en producción)
-- Suponemos que el id de Ciprofloxacin será 1 (si la tabla estaba vacía). Si no, ajustar

-- Breakpoint (disk, mm)
INSERT INTO breakpoints (antibiotic_id, standard, version, organism, method, unit, s_upper, i_lower, i_upper, r_lower, note)
VALUES
(1,'CLSI','2024','Neisseria gonorrhoeae','disk','mm',31,21,30,20,'Ejemplo: Disk diffusion breakpoints (mm) - valores ilustrativos');

-- Breakpoint (mic, ug/mL)
INSERT INTO breakpoints (antibiotic_id, standard, version, organism, method, unit, s_upper, i_lower, i_upper, r_lower, note)
VALUES
(1,'CLSI','2024','Neisseria gonorrhoeae','mic','ug/mL',0.06,0.12,0.5,1,'Ejemplo: MIC breakpoints (ug/mL) - valores ilustrativos');

-- Nota: Los valores anteriores son para pruebas. Reemplazar con valores oficiales de CLSI/EUCAST cuando estén disponibles.
