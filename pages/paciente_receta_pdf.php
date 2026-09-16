<?php
/**
 * pages/paciente_receta_pdf.php
 *
 * Genera un PDF con la receta indicada usando FPDF. Verifica que la
 * receta pertenezca al paciente logueado antes de generar.
 *
 * Uso: paciente_receta_pdf.php?id_receta=X
 */
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
verificarSesionPaciente();

$idReceta = filter_input(INPUT_GET, 'id_receta', FILTER_VALIDATE_INT);
if (!$idReceta) {
    http_response_code(400);
    exit('ID de receta inválido.');
}

$idPaciente = (int) $_SESSION['id_paciente'];

// Traer la receta SOLO si pertenece al paciente logueado
$stmt = $conexion->prepare(
    'SELECT r.id_receta, r.fecha_emision,
            con.id_consulta, con.fecha_consulta, con.motivo, con.diagnostico, con.observaciones,
            p.id_paciente, p.nombre AS paciente_nombre, p.apellido AS paciente_apellido,
            p.cedula,
            u.nombre AS medico_nombre, u.apellido AS medico_apellido,
            e.nombre_especialidad
     FROM recetas r
     INNER JOIN consultas con ON con.id_consulta = r.id_consulta
     INNER JOIN pacientes p   ON p.id_paciente = con.id_paciente
     INNER JOIN medicos m     ON m.id_medico = con.id_medico
     INNER JOIN usuarios u    ON u.id_usuario = m.id_usuario
     INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
     WHERE r.id_receta = :id_receta AND con.id_paciente = :id_paciente
     LIMIT 1'
);
$stmt->execute([
    ':id_receta'  => $idReceta,
    ':id_paciente' => $idPaciente,
]);
$receta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receta) {
    http_response_code(404);
    exit('Receta no encontrada o no le pertenece.');
}

// Medicamentos
$stmtDet = $conexion->prepare(
    'SELECT rd.dosis, rd.frecuencia, rd.duracion,
            med.nombre_medicamento, med.presentacion
     FROM receta_detalle rd
     INNER JOIN medicamentos med ON med.id_medicamento = rd.id_medicamento
     WHERE rd.id_receta = :id_receta
     ORDER BY med.nombre_medicamento'
);
$stmtDet->execute([':id_receta' => $idReceta]);
$medicamentos = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

// Helper: convertir UTF-8 a ISO-8859-1 (FPDF usa WinAnsiEncoding = latin1)
$enc = static function (string $s): string {
    if ($s === '') return $s;
    $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $out === false ? $s : $out;
};

// Generar PDF
require_once __DIR__ . '/../vendor/fpdf/fpdf.php';

class PDFReceta extends FPDF
{
    public function Header()
    {
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(0, 7, iconv('UTF-8', 'ISO-8859-1', 'Hospital Raúl Dávila Mena'), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 5, iconv('UTF-8', 'ISO-8859-1', 'Sistema de Consultas'), 0, 1, 'C');
        $this->Ln(4);
        $this->SetDrawColor(27, 122, 92); // verde-azulado
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(6);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, iconv('UTF-8', 'ISO-8859-1', 'Receta generada por el panel del paciente - Hospital Raúl Dávila Mena'), 0, 0, 'C');
    }
}

$pdf = new PDFReceta('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// --- Encabezado de la receta ---
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(0, 7, $enc('RECETA MÉDICA'), 0, 1, 'C');
$pdf->Ln(2);

// Datos del paciente
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(30, 6, $enc('Paciente:'), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(0, 6, $enc($receta['paciente_nombre'] . ' ' . $receta['paciente_apellido']), 0, 1);

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(30, 6, $enc('Cédula:'), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(0, 6, $enc($receta['cedula']), 0, 1);

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(30, 6, $enc('Médico:'), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(0, 6, $enc('Dr(a). ' . $receta['medico_nombre'] . ' ' . $receta['medico_apellido']), 0, 1);

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(30, 6, $enc('Especialidad:'), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(0, 6, $enc($receta['nombre_especialidad']), 0, 1);

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(30, 6, $enc('Fecha emisión:'), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(0, 6, $enc(date('d/m/Y H:i', strtotime($receta['fecha_emision']))), 0, 1);

$pdf->Ln(4);

// --- Diagnóstico / motivo (contexto clínico) ---
if (!empty($receta['motivo']) || !empty($receta['diagnostico'])) {
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->SetFillColor(241, 250, 247);
    $pdf->SetFont('Helvetica', 'B', 10);

    $yInicial = $pdf->GetY();
    $pdf->MultiCell(0, 6, '', 0, 'L', false); // Línea base para el rect
    $pdf->SetXY(15, $yInicial);

    if (!empty($receta['motivo'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $enc('Motivo de consulta:'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5, $enc($receta['motivo']), 0, 'L');
    }
    if (!empty($receta['diagnostico'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $enc('Diagnóstico:'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5, $enc($receta['diagnostico']), 0, 'L');
    }
    $pdf->Ln(3);
}

// --- Medicamentos ---
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetFillColor(27, 122, 92);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, $enc('MEDICAMENTOS'), 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

if (count($medicamentos) === 0) {
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->Cell(0, 6, $enc('(Sin medicamentos registrados en esta receta)'), 0, 1, 'C');
} else {
    foreach ($medicamentos as $i => $med) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(8, 6, ($i + 1) . '.', 0, 0);
        $pdf->Cell(0, 6, $enc($med['nombre_medicamento'] . ($med['presentacion'] ? ' — ' . $med['presentacion'] : '')), 0, 1);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(8, 5, '', 0, 0);
        $pdf->Cell(0, 5,
            $enc('Dosis: ' . $med['dosis']
               . '  |  Frecuencia: ' . $med['frecuencia']
               . '  |  Duración: ' . $med['duracion']),
            0, 1
        );
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }
}

// --- Pie de firma ---
$pdf->Ln(15);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(95, 5, '', 0, 0);
$pdf->Cell(95, 5, '______________________________', 0, 1);
$pdf->Cell(95, 5, '', 0, 0);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(95, 5, $enc('Dr(a). ' . $receta['medico_nombre'] . ' ' . $receta['medico_apellido']), 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 5, '', 0, 0);
$pdf->Cell(95, 5, $enc($receta['nombre_especialidad']), 0, 1, 'C');

// Salida: descargar
$pdf->Output('D', 'receta_' . $idReceta . '.pdf', true);
exit;
