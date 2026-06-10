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
    $entries = [
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
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO resources (title, author, year, type, excerpt, source_url, submitter_email, status)
         VALUES (?, ?, ?, ?, ?, ?, 'seed@sitio', 'approved')"
    );
    foreach ($entries as [$year, $author, $type, $title, $excerpt, $url]) {
        $stmt->execute([$title, $author, $year, $type, $excerpt, $url]);
    }
}
