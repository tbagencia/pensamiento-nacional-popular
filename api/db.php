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
        author TEXT NOT NULL,
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

    if ($isNew) {
        seed($pdo);
    }
    return $pdo;
}

/** Seed with public-domain historical entries so the timeline is not empty. */
function seed(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO resources (title, author, year, type, excerpt, source_url, submitter_email, status)
         VALUES (?, ?, ?, ?, ?, ?, 'seed@sitio', 'approved')"
    );
    foreach (seed_entries() as [$year, $author, $type, $title, $excerpt, $url]) {
        $stmt->execute([$title, $author, $year, $type, $excerpt, $url]);
    }
}

/** Historical entries: [year, author, type, title, excerpt, source_url]. */
function seed_entries(): array
{
    return [
        [1810, 'Mariano Moreno', 'texto', 'Plan de Operaciones',
         'Documento atribuido a Mariano Moreno con el programa económico y político de la Revolución de Mayo: industrialización, comercio dirigido por el Estado y defensa de la soberanía.',
         'https://es.wikisource.org/wiki/Plan_de_operaciones'],
        [1879, 'José Hernández', 'texto', 'La vuelta de Martín Fierro',
         'Segunda parte del poema nacional. Reivindicado por el pensamiento nacional como la voz del gaucho frente al proyecto excluyente de las élites: "Los hermanos sean unidos, porque ésa es la ley primera".',
         'https://es.wikisource.org/wiki/La_vuelta_de_Mart%C3%ADn_Fierro'],
        [1916, 'Hipólito Yrigoyen', 'discurso', 'Primer mensaje al Congreso',
         'Asunción del primer gobierno elegido por sufragio universal masculino (Ley Sáenz Peña). Yrigoyen plantea la "reparación" institucional y social frente al régimen conservador.',
         null],
        [1931, 'Raúl Scalabrini Ortiz', 'texto', 'El hombre que está solo y espera',
         'Ensayo sobre el "hombre de Corrientes y Esmeralda", arquetipo del porteño y del ser nacional. Punto de partida de la obra de Scalabrini sobre la dependencia económica argentina.',
         null],
        [1935, 'FORJA (Jauretche, Scalabrini Ortiz y otros)', 'texto', 'Manifiesto fundacional de FORJA',
         'La Fuerza de Orientación Radical de la Joven Argentina denuncia la Década Infame: "Somos una Argentina colonial, queremos ser una Argentina libre".',
         null],
        [1945, 'Juan Domingo Perón', 'discurso', 'Discurso del 17 de octubre',
         'Desde el balcón de la Casa Rosada, tras la movilización popular que exigió su liberación, Perón sella el vínculo con los trabajadores en la jornada fundacional del peronismo.',
         null],
        [1947, 'Eva Perón', 'discurso', 'Anuncio de la Ley de Voto Femenino',
         'Evita anuncia por cadena nacional la promulgación de la ley 13.010 de sufragio femenino: "Aquí está, hermanas mías, resumida en la letra apretada de pocos artículos, una historia larga de luchas".',
         null],
        [1949, 'Juan Domingo Perón', 'texto', 'La comunidad organizada',
         'Discurso de cierre del Congreso Nacional de Filosofía en Mendoza. Base doctrinaria de la tercera posición: ni individualismo ni colectivismo, la comunidad organizada.',
         null],
        [1951, 'Eva Perón', 'discurso', 'El Renunciamiento',
         'En el Cabildo Abierto del Justicialismo, ante una multitud que pedía su candidatura a vicepresidenta, Evita renuncia a los honores: "No renuncio a la lucha ni al trabajo, renuncio a los honores".',
         null],
        [1957, 'John William Cooke', 'texto', 'Cartas Perón-Cooke',
         'Correspondencia entre Perón en el exilio y Cooke, su delegado en Argentina, durante la resistencia peronista. Documento clave de la radicalización del movimiento.',
         null],
        [1968, 'Arturo Jauretche', 'texto', 'Manual de zonceras argentinas',
         'Jauretche desarma las "zonceras": ideas repetidas sin pensar que sostienen la colonización pedagógica, empezando por la zoncera madre: "civilización y barbarie".',
         null],
        [1973, 'Juan Domingo Perón', 'discurso', 'Discurso de regreso definitivo',
         'Tras 18 años de proscripción y exilio, Perón regresa al país y convoca a la reconstrucción nacional y a la unidad de los argentinos.',
         null],
        [2003, 'Néstor Kirchner', 'discurso', 'Discurso de asunción presidencial',
         'Ante la Asamblea Legislativa, Kirchner define su pertenencia: "Formo parte de una generación diezmada, castigada con dolorosas ausencias; me sumé a las luchas políticas creyendo en valores y convicciones a las que no pienso dejar en la puerta de entrada de la Casa Rosada".',
         null],
        [1834, 'Juan Manuel de Rosas', 'carta', 'Carta de la Hacienda de Figueroa',
         'Carta a Facundo Quiroga donde Rosas expone su concepción del orden federal: organizar primero las provincias antes que dictar una constitución, en defensa del federalismo frente al centralismo porteño.',
         null],
        [1872, 'José Hernández', 'texto', 'El gaucho Martín Fierro',
         'La primera parte del poema nacional: denuncia de la leva, el fortín y el despojo del gaucho por el Estado liberal. La voz de los excluidos del proyecto agroexportador.',
         'https://es.wikisource.org/wiki/El_Gaucho_Mart%C3%ADn_Fierro'],
        [1904, 'Juan Bialet Massé', 'texto', 'Informe sobre el estado de las clases obreras',
         'Encargado por el ministro Joaquín V. González, el informe recorre el interior del país y documenta las condiciones de vida y trabajo de obreros y peones. Pieza fundante de la cuestión social argentina.',
         null],
        [1910, 'Manuel Ugarte', 'texto', 'El porvenir de la América Latina',
         'Ugarte plantea la unidad latinoamericana frente al avance del imperialismo: la patria grande como destino común de las repúblicas hispanoamericanas.',
         null],
        [1912, 'Roque Sáenz Peña', 'discurso', '"Quiera el pueblo votar"',
         'Mensaje en defensa de la ley de sufragio secreto, universal y obligatorio que termina con el fraude electoral del régimen conservador y abre paso a la democracia de masas.',
         null],
        [1918, 'Deodoro Roca y la Federación Universitaria de Córdoba', 'manifiesto', 'Manifiesto Liminar de la Reforma Universitaria',
         '"Los dolores que quedan son las libertades que faltan": la juventud cordobesa sacude la universidad clerical y elitista, y proyecta la reforma a toda América Latina.',
         'https://es.wikisource.org/wiki/Manifiesto_liminar_de_la_Reforma_Universitaria'],
        [1934, 'Arturo Jauretche', 'texto', 'El Paso de los Libres',
         'Poema gauchesco que narra el levantamiento radical de 1933 contra el gobierno surgido del fraude. Primera obra de Jauretche, con prólogo de Jorge Luis Borges.',
         null],
        [1936, 'Enrique Mosconi', 'texto', 'El petróleo argentino',
         'El general que dirigió YPF expone su doctrina: el petróleo como recurso estratégico de la Nación, contra los trusts extranjeros y por la soberanía energética.',
         null],
        [1940, 'Raúl Scalabrini Ortiz', 'texto', 'Política británica en el Río de la Plata',
         'Investigación que desnuda los mecanismos de la dependencia: ferrocarriles, frigoríficos y finanzas. Obra central del revisionismo económico nacional.',
         null],
        [1946, 'Juan Domingo Perón', 'discurso', 'Discurso de asunción presidencial',
         'Perón asume su primer gobierno y formula el programa de la nueva Argentina: justicia social, independencia económica y soberanía política.',
         null],
        [1948, 'Leopoldo Marechal', 'texto', 'Adán Buenosayres',
         'Novela total de Buenos Aires. Marechal, el gran escritor del peronismo, funde vanguardia y tradición criolla; ignorada por la crítica liberal durante décadas, hoy es un clásico.',
         null],
        [1951, 'Eva Perón', 'texto', 'La razón de mi vida',
         'Autobiografía doctrinaria de Evita: su encuentro con Perón, los descamisados, la justicia social y el papel de la mujer en la política argentina.',
         null],
        [1957, 'Rodolfo Walsh', 'texto', 'Operación Masacre',
         'Investigación sobre los fusilamientos de José León Suárez tras el levantamiento de Valle. Funda el periodismo de investigación y la no ficción en castellano, antes que Capote.',
         null],
        [1960, 'Juan José Hernández Arregui', 'texto', 'La formación de la conciencia nacional',
         'Estudio del desarrollo del pensamiento nacional frente a la cultura de la dependencia. Obra clave de la izquierda nacional argentina.',
         null],
        [1969, 'Agustín Tosco', 'discurso', 'Proclama del Cordobazo',
         'El dirigente de Luz y Fuerza convoca a la huelga activa del 29 de mayo: obreros y estudiantes unidos toman las calles de Córdoba contra la dictadura de Onganía.',
         null],
        [1973, 'Héctor J. Cámpora', 'discurso', 'Discurso de asunción presidencial',
         'Tras 18 años de proscripción del peronismo, "El Tío" asume con el compromiso del gobierno popular: "La sangre derramada no será negociada".',
         null],
        [1974, 'Juan Domingo Perón', 'texto', 'Modelo argentino para el proyecto nacional',
         'Testamento político de Perón, leído ante el Congreso: la comunidad organizada, la unidad latinoamericana y el universalismo como horizonte de la Nación.',
         null],
        [1977, 'Rodolfo Walsh', 'carta', 'Carta Abierta de un Escritor a la Junta Militar',
         'A un año del golpe, Walsh documenta el terror y el plan económico de la dictadura. La envió por correo el 24 de marzo de 1977; fue secuestrado al día siguiente.',
         'https://es.wikisource.org/wiki/Carta_Abierta_de_un_Escritor_a_la_Junta_Militar'],
        [2005, 'Néstor Kirchner', 'discurso', 'Discurso contra el ALCA en Mar del Plata',
         'En la IV Cumbre de las Américas, Argentina, Brasil y Venezuela rechazan el Área de Libre Comercio de las Américas. Hito de la integración regional sudamericana.',
         null],
        [2010, 'Cristina Fernández de Kirchner', 'discurso', 'Discurso del Bicentenario',
         'En los festejos del Bicentenario de la Revolución de Mayo, ante una multitud, CFK reivindica la historia de las luchas populares argentinas y la patria grande latinoamericana.',
         null],
    ];
}
