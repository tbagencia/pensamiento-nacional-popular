<?php
/**
 * SQLite connection + schema bootstrap + initial seed.
 * The database file is created automatically on first run.
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS resources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        year INTEGER NOT NULL,
        type TEXT NOT NULL DEFAULT 'texto',
        excerpt TEXT NOT NULL,
        source_url TEXT,
        submitter_email TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending_email',
        verify_token TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_year ON resources(year)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_status ON resources(status)');

    // Authors are first-class rows: the id is the stable identity, the
    // name is just its current spelling. A document's author label is
    // always composed from its linked authors — never stored twice.
    $pdo->exec("CREATE TABLE IF NOT EXISTS authors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_authors (
        resource_id INTEGER NOT NULL,
        author_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (resource_id, author_id)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resource_authors_author ON resource_authors(author_id)');

    if ($isNew) {
        seed($pdo);
        $pdo->exec('PRAGMA user_version = 3');
    }
    backfill_seed_source_urls($pdo);
    migrate_to_author_ids($pdo);
    return $pdo;
}

/**
 * Author names out of a comma-separated input string. Commas inside
 * parentheses do not split, mirroring the author-tags front end where
 * a comma turns the text into a new pill.
 */
function canonical_authors(string $authors): array
{
    $names = array_values(array_filter(array_map(
        'trim',
        preg_split('/,(?![^()]*\))/', $authors)
    )));
    return $names ?: [trim($authors)];
}

/** Replaces a resource's author links from a comma-separated names string. */
function set_resource_authors(PDO $pdo, int $id, string $authors): void
{
    $pdo->prepare('DELETE FROM resource_authors WHERE resource_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO authors (name) VALUES (?)');
    $select = $pdo->prepare('SELECT id FROM authors WHERE name = ?');
    $link = $pdo->prepare(
        'INSERT OR IGNORE INTO resource_authors (resource_id, author_id, position) VALUES (?, ?, ?)'
    );
    foreach (canonical_authors($authors) as $position => $name) {
        $insert->execute([$name]);
        $select->execute([$name]);
        $link->execute([$id, (int) $select->fetchColumn(), $position]);
    }
}

/** Author names of a resource, in display order. */
function resource_author_names(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT a.name FROM resource_authors ra
         JOIN authors a ON a.id = ra.author_id
         WHERE ra.resource_id = ? ORDER BY ra.position'
    );
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * SQL expression composing a resource's author label ("Name, Name") from
 * its linked authors, for use in SELECT lists. $table is the alias of
 * the resources table in the outer query.
 */
function author_label_sql(string $table): string
{
    return "(SELECT GROUP_CONCAT(name, ', ') FROM (
        SELECT a.name FROM resource_authors ra
        JOIN authors a ON a.id = ra.author_id
        WHERE ra.resource_id = $table.id
        ORDER BY ra.position
    ))";
}

/**
 * One-off migration (PRAGMA user_version 3): authors become first-class
 * rows. Canonical name strings (v2) or legacy display strings (v0/v1)
 * turn into authors + id links, and resources drops its author column —
 * the label is composed from the linked authors from here on.
 */
function migrate_to_author_ids(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 3) {
        return;
    }
    $tableColumns = fn (string $table): array => $pdo
        ->query("SELECT name FROM pragma_table_info('$table')")
        ->fetchAll(PDO::FETCH_COLUMN);

    $pdo->exec('BEGIN');

    // v2 shape: resource_authors holds canonical names. Collect them
    // (they carry moderator fixes and the Perón-Cooke co-authorship)
    // and rebuild the table in its id shape.
    $names = [];
    if (in_array('author', $tableColumns('resource_authors'), true)) {
        foreach ($pdo->query('SELECT resource_id, author FROM resource_authors ORDER BY rowid') as $row) {
            $names[(int) $row['resource_id']][] = $row['author'];
        }
        $pdo->exec('DROP TABLE resource_authors');
        $pdo->exec("CREATE TABLE resource_authors (
            resource_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (resource_id, author_id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resource_authors_author ON resource_authors(author_id)');
    }

    if (in_array('author', $tableColumns('resources'), true)) {
        // v0/v1 shape: no canonical rows yet, derive names from the
        // display string. The map covers legacy seed spellings.
        if ($names === []) {
            $map = [
                'FORJA (Jauretche, Scalabrini Ortiz y otros)' => 'Arturo Jauretche, Raúl Scalabrini Ortiz',
                'Deodoro Roca y la Federación Universitaria de Córdoba' => 'Deodoro Roca',
            ];
            foreach ($pdo->query('SELECT id, author, title FROM resources') as $row) {
                $authors = $map[$row['author']] ?? $row['author'];
                if ($row['title'] === 'Cartas Perón-Cooke' && !str_contains($authors, 'Juan Domingo Perón')) {
                    $authors .= ', Juan Domingo Perón';
                }
                $names[(int) $row['id']] = canonical_authors($authors);
            }
        }

        $pdo->exec("CREATE TABLE resources_v3 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            year INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'texto',
            excerpt TEXT NOT NULL,
            source_url TEXT,
            submitter_email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending_email',
            verify_token TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('INSERT INTO resources_v3 (id, title, year, type, excerpt, source_url, submitter_email, status, verify_token, created_at)
                    SELECT id, title, year, type, excerpt, source_url, submitter_email, status, verify_token, created_at FROM resources');
        $pdo->exec('DROP TABLE resources');
        $pdo->exec('ALTER TABLE resources_v3 RENAME TO resources');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_year ON resources(year)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_status ON resources(status)');
    }

    foreach ($names as $resourceId => $resourceNames) {
        set_resource_authors($pdo, $resourceId, implode(', ', $resourceNames));
    }

    $pdo->exec('PRAGMA user_version = 3');
    $pdo->exec('COMMIT');
}

/**
 * One-off migration for databases created before the seed carried reference
 * links: fills source_url on rows matching a seed entry by year + title.
 * PRAGMA user_version gates it so it only runs once per database.
 */
function backfill_seed_source_urls(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 1) {
        return;
    }
    $stmt = $pdo->prepare(
        "UPDATE resources SET source_url = ?
         WHERE year = ? AND title = ? AND (source_url IS NULL OR source_url = '')"
    );
    foreach (seed_entries() as [$year, , , $title, , $url]) {
        if ($url !== null) {
            $stmt->execute([$url, $year, $title]);
        }
    }
    $pdo->exec('PRAGMA user_version = 1');
}

/** Seed with public-domain historical entries so the timeline is not empty. */
function seed(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO resources (title, year, type, excerpt, source_url, submitter_email, status)
         VALUES (?, ?, ?, ?, ?, 'seed@sitio', 'approved')"
    );
    foreach (seed_entries() as [$year, $authors, $type, $title, $excerpt, $url]) {
        $stmt->execute([$title, $year, $type, $excerpt, $url]);
        set_resource_authors($pdo, (int) $pdo->lastInsertId(), $authors);
    }
}

/** Historical entries: [year, author, type, title, excerpt, source_url]. */
function seed_entries(): array
{
    return [
        [1810, 'Mariano Moreno', 'texto', 'Plan de Operaciones',
         'Documento atribuido a Mariano Moreno con el programa económico y político de la Revolución de Mayo: industrialización, comercio dirigido por el Estado y defensa de la soberanía.',
         'https://es.wikisource.org/wiki/Plan_de_operaciones'],
        [1879, 'José Hernández', 'poema', 'La vuelta de Martín Fierro',
         'Segunda parte del poema nacional. Reivindicado por el pensamiento nacional como la voz del gaucho frente al proyecto excluyente de las élites: "Los hermanos sean unidos, porque ésa es la ley primera".',
         'https://es.wikisource.org/wiki/La_vuelta_de_Mart%C3%ADn_Fierro'],
        [1916, 'Hipólito Yrigoyen', 'discurso', 'Primer mensaje al Congreso',
         'Asunción del primer gobierno elegido por sufragio universal masculino (Ley Sáenz Peña). Yrigoyen plantea la "reparación" institucional y social frente al régimen conservador.',
         'https://elhistoriador.com.ar/primer-discurso-de-hipolito-yrigoyen-como-presidente-1916-a-100-anos-las-elecciones-que-lo-consagraron-primer-mandatario/'],
        [1931, 'Raúl Scalabrini Ortiz', 'ensayo', 'El hombre que está solo y espera',
         'Ensayo sobre el "hombre de Corrientes y Esmeralda", arquetipo del porteño y del ser nacional. Punto de partida de la obra de Scalabrini sobre la dependencia económica argentina.',
         'https://www.perio.unlp.edu.ar/catedras/escrituraargentina/wp-content/uploads/sites/150/2020/07/5.-Ra%C3%BAl-Scalabrini-Ortiz-El-hombre-que-est%C3%A1-solo-y-espera.pdf'],
        [1935, 'Arturo Jauretche, Raúl Scalabrini Ortiz', 'manifiesto', 'Manifiesto fundacional de FORJA',
         'La Fuerza de Orientación Radical de la Joven Argentina denuncia la Década Infame: "Somos una Argentina colonial, queremos ser una Argentina libre".',
         'https://elhistoriador.com.ar/manifiesto-de-la-fundacion-de-forja/'],
        [1945, 'Juan Domingo Perón', 'discurso', 'Discurso del 17 de octubre',
         'Desde el balcón de la Casa Rosada, tras la movilización popular que exigió su liberación, Perón sella el vínculo con los trabajadores en la jornada fundacional del peronismo.',
         'https://www.educ.ar/recursos/129178/discurso-de-juan-d-peron-17-de-octubre-de-1945'],
        [1947, 'Eva Perón', 'discurso', 'Anuncio de la Ley de Voto Femenino',
         'Evita anuncia por cadena nacional la promulgación de la ley 13.010 de sufragio femenino: "Aquí está, hermanas mías, resumida en la letra apretada de pocos artículos, una historia larga de luchas".',
         'https://elhistoriador.com.ar/anuncio-de-la-ley-del-voto-femenino-evita/'],
        [1949, 'Juan Domingo Perón', 'discurso', 'La comunidad organizada',
         'Discurso de cierre del Congreso Nacional de Filosofía en Mendoza. Base doctrinaria de la tercera posición: ni individualismo ni colectivismo, la comunidad organizada.',
         'https://bcn.gob.ar/uploads/Peron-comunidad-organizada.pdf'],
        [1951, 'Eva Perón', 'discurso', 'El Renunciamiento',
         'En el Cabildo Abierto del Justicialismo, ante una multitud que pedía su candidatura a vicepresidenta, Evita renuncia a los honores: "No renuncio a la lucha ni al trabajo, renuncio a los honores".',
         'https://elhistoriador.com.ar/el-renunciamiento-de-evita-31-de-agosto-de-1951/'],
        [1957, 'John William Cooke, Juan Domingo Perón', 'carta', 'Cartas Perón-Cooke',
         'Correspondencia entre Perón en el exilio y Cooke, su delegado en Argentina, durante la resistencia peronista. Documento clave de la radicalización del movimiento.',
         'https://cedinpe.unsam.edu.ar/content/correspondencia-per%C3%B3n-cooke'],
        [1968, 'Arturo Jauretche', 'libro', 'Manual de zonceras argentinas',
         'Jauretche desarma las "zonceras": ideas repetidas sin pensar que sostienen la colonización pedagógica, empezando por la zoncera madre: "civilización y barbarie".',
         'https://cedinpe.unsam.edu.ar/content/jauretche-arturo-manual-de-zonceras-argentinas'],
        [1973, 'Juan Domingo Perón', 'discurso', 'Discurso de regreso definitivo',
         'Tras 18 años de proscripción y exilio, Perón regresa al país y convoca a la reconstrucción nacional y a la unidad de los argentinos.',
         'https://www.pjbonaerense.org.ar/discurso-de-juan-domingo-peron-ano-1973/'],
        [2003, 'Néstor Kirchner', 'discurso', 'Discurso de asunción presidencial',
         'Ante la Asamblea Legislativa, Kirchner define su pertenencia: "Formo parte de una generación diezmada, castigada con dolorosas ausencias; me sumé a las luchas políticas creyendo en valores y convicciones a las que no pienso dejar en la puerta de entrada de la Casa Rosada".',
         'https://www.casarosada.gob.ar/informacion/archivo/24414-blank-18980869'],
        [1834, 'Juan Manuel de Rosas', 'carta', 'Carta de la Hacienda de Figueroa',
         'Carta a Facundo Quiroga donde Rosas expone su concepción del orden federal: organizar primero las provincias antes que dictar una constitución, en defensa del federalismo frente al centralismo porteño.',
         'https://hum.unne.edu.ar/academica/departamentos/historia/catedras/hist_argen_indep/otros/carta_rosas_hacienda_figueroa.pdf'],
        [1872, 'José Hernández', 'poema', 'El gaucho Martín Fierro',
         'La primera parte del poema nacional: denuncia de la leva, el fortín y el despojo del gaucho por el Estado liberal. La voz de los excluidos del proyecto agroexportador.',
         'https://es.wikisource.org/wiki/El_Gaucho_Mart%C3%ADn_Fierro'],
        [1904, 'Juan Bialet Massé', 'texto', 'Informe sobre el estado de las clases obreras',
         'Encargado por el ministro Joaquín V. González, el informe recorre el interior del país y documenta las condiciones de vida y trabajo de obreros y peones. Pieza fundante de la cuestión social argentina.',
         'https://www.argentina.gob.ar/trabajo/biblioteca/informemasse'],
        [1910, 'Manuel Ugarte', 'ensayo', 'El porvenir de la América Latina',
         'Ugarte plantea la unidad latinoamericana frente al avance del imperialismo: la patria grande como destino común de las repúblicas hispanoamericanas.',
         'https://www.cervantesvirtual.com/obra/el-porvenir-de-la-america-latina-la-raza-la-integridad-territorial-y-moral-la-organizacion-interior-780660/'],
        [1912, 'Roque Sáenz Peña', 'discurso', '"Quiera el pueblo votar"',
         'Mensaje en defensa de la ley de sufragio secreto, universal y obligatorio que termina con el fraude electoral del régimen conservador y abre paso a la democracia de masas.',
         'https://elhistoriador.com.ar/roque-saenz-pena-quiera-el-pueblo-votar/'],
        [1918, 'Deodoro Roca', 'manifiesto', 'Manifiesto Liminar de la Reforma Universitaria',
         '"Los dolores que quedan son las libertades que faltan": la juventud cordobesa sacude la universidad clerical y elitista, y proyecta la reforma a toda América Latina.',
         'https://es.wikisource.org/wiki/Manifiesto_liminar_de_la_Reforma_Universitaria'],
        [1934, 'Arturo Jauretche', 'poema', 'El Paso de los Libres',
         'Poema gauchesco que narra el levantamiento radical de 1933 contra el gobierno surgido del fraude. Primera obra de Jauretche, con prólogo de Jorge Luis Borges.',
         'https://cedinpe.unsam.edu.ar/content/jauretche-arturo-el-paso-de-los-libres-relato-gaucho-de-la-%C3%BAltima-revoluci%C3%B3n-radical-0'],
        [1936, 'Enrique Mosconi', 'libro', 'El petróleo argentino',
         'El general que dirigió YPF expone su doctrina: el petróleo como recurso estratégico de la Nación, contra los trusts extranjeros y por la soberanía energética.',
         'https://elhistoriador.com.ar/enrique-mosconi-y-el-petroleo-como-fuente-de-progreso/'],
        [1940, 'Raúl Scalabrini Ortiz', 'libro', 'Política británica en el Río de la Plata',
         'Investigación que desnuda los mecanismos de la dependencia: ferrocarriles, frigoríficos y finanzas. Obra central del revisionismo económico nacional.',
         'https://institutorosas.cultura.gob.ar/media/uploads/site-35/multimedia/cuaderno-n1.-politica-britanica-en-el-rio-de-la-plata.-raul-scalabrini-ortiz.pdf'],
        [1946, 'Juan Domingo Perón', 'discurso', 'Discurso de asunción presidencial',
         'Perón asume su primer gobierno y formula el programa de la nueva Argentina: justicia social, independencia económica y soberanía política.',
         'https://bcn.gob.ar/uploads/Peron-DOSSIER-legislativoAVIN151-Mensajes-presidenciales.-Mensaje-de-asuncion.-Congreso-Legislativo-de-la-Nacion-Argentina.pdf'],
        [1948, 'Leopoldo Marechal', 'libro', 'Adán Buenosayres',
         'Novela total de Buenos Aires. Marechal, el gran escritor del peronismo, funde vanguardia y tradición criolla; ignorada por la crítica liberal durante décadas, hoy es un clásico.',
         'https://es.wikipedia.org/wiki/Ad%C3%A1n_Buenosayres'],
        [1951, 'Eva Perón', 'libro', 'La razón de mi vida',
         'Autobiografía doctrinaria de Evita: su encuentro con Perón, los descamisados, la justicia social y el papel de la mujer en la política argentina.',
         'https://archive.org/details/LaRazonDeMiVida'],
        [1957, 'Rodolfo Walsh', 'libro', 'Operación Masacre',
         'Investigación sobre los fusilamientos de José León Suárez tras el levantamiento de Valle. Funda el periodismo de investigación y la no ficción en castellano, antes que Capote.',
         'https://www.educ.ar/recursos/113791/operacion-masacre'],
        [1960, 'Juan José Hernández Arregui', 'ensayo', 'La formación de la conciencia nacional',
         'Estudio del desarrollo del pensamiento nacional frente a la cultura de la dependencia. Obra clave de la izquierda nacional argentina.',
         'https://cedinpe.unsam.edu.ar/content/hern%C3%A1ndez-arregui-juan-j-la-formaci%C3%B3n-de-la-conciencia-nacional-1930-1960'],
        [1969, 'Agustín Tosco', 'discurso', 'Proclama del Cordobazo',
         'El dirigente de Luz y Fuerza convoca a la huelga activa del 29 de mayo: obreros y estudiantes unidos toman las calles de Córdoba contra la dictadura de Onganía.',
         'https://www.educ.ar/recursos/128830/agustin-tosco-desde-la-carcel-luego-de-cordobazo'],
        [1973, 'Héctor J. Cámpora', 'discurso', 'Discurso de asunción presidencial',
         'Tras 18 años de proscripción del peronismo, "El Tío" asume con el compromiso del gobierno popular: "La sangre derramada no será negociada".',
         'https://www.cedinpe.unsam.edu.ar/content/campora-hector-j-esto-hara-el-gobierno-popular-texto-completo-del-discurso-pronunciado-ante'],
        [1974, 'Juan Domingo Perón', 'ensayo', 'Modelo argentino para el proyecto nacional',
         'Testamento político de Perón, leído ante el Congreso: la comunidad organizada, la unidad latinoamericana y el universalismo como horizonte de la Nación.',
         'https://digitales.bcn.gob.ar/files/textos/Peron.-Modelo-argentino-para-el-proyecto-nacional.pdf'],
        [1977, 'Rodolfo Walsh', 'carta', 'Carta Abierta de un Escritor a la Junta Militar',
         'A un año del golpe, Walsh documenta el terror y el plan económico de la dictadura. La envió por correo el 24 de marzo de 1977; fue secuestrado al día siguiente.',
         'https://es.wikisource.org/wiki/Carta_Abierta_de_un_Escritor_a_la_Junta_Militar'],
        [2005, 'Néstor Kirchner', 'discurso', 'Discurso contra el ALCA en Mar del Plata',
         'En la IV Cumbre de las Américas, Argentina, Brasil y Venezuela rechazan el Área de Libre Comercio de las Américas. Hito de la integración regional sudamericana.',
         'https://www.cfkargentina.com/nestor-kirchner-en-la-iv-cumbre-de-las-americas-en-mar-del-plata/'],
        [2010, 'Cristina Fernández de Kirchner', 'discurso', 'Discurso del Bicentenario',
         'En los festejos del Bicentenario de la Revolución de Mayo, ante una multitud, CFK reivindica la historia de las luchas populares argentinas y la patria grande latinoamericana.',
         'https://www.casarosada.gob.ar/informacion/archivo/22233-blank-31757128'],
    ];
}
