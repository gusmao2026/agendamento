<?php
header("Content-Type: application/json; charset=utf-8");
require_once "conexao.php";

$aulas = ["07:00","08:00","09:20","10:20","11:20","13:00","14:00","15:00"];

function responder($dados, $codigo=200) {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function quebrados($db,$recurso) {
    $sql = "SELECT COALESCE(SUM(
        CASE
          WHEN acao='quebrado' THEN quantidade
          WHEN acao='reparado' THEN -quantidade
          ELSE 0
        END
    ),0) FROM agenda_manutencoes WHERE recurso=?";
    $s=$db->prepare($sql);
    $s->execute([$recurso]);
    return max(0,(int)$s->fetchColumn());
}

try {
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        if (($_GET["action"] ?? "") !== "listar") {
            responder(["ok"=>false,"error"=>"Ação inválida."],400);
        }

        $reservas=$conexao->query(
            "SELECT * FROM agenda_reservas ORDER BY data,aula"
        )->fetchAll();

        $manutencoes=$conexao->query(
            "SELECT * FROM agenda_manutencoes ORDER BY quando DESC"
        )->fetchAll();

        $recursos=$conexao->query(
            "SELECT * FROM agenda_recursos ORDER BY nome"
        )->fetchAll();

        responder([
            "ok"=>true,
            "reservas"=>$reservas,
            "manutencoes"=>$manutencoes,
            "recursos"=>$recursos,
            "aulas"=>$aulas
        ]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        responder(["ok"=>false,"error"=>"Método inválido."],405);
    }

    $dados=json_decode(file_get_contents("php://input"),true);
    if (!is_array($dados)) {
        responder(["ok"=>false,"error"=>"Dados inválidos."],400);
    }

    $action=$dados["action"] ?? "";

    if ($action==="criarReserva") {
        $r=$dados["reserva"] ?? [];

        $id=trim($r["id"] ?? "");
        $professor=trim($r["professor"] ?? "");
        $recurso=trim($r["recurso"] ?? "");
        $data=$r["data"] ?? "";
        $aula=filter_var($r["aula"] ?? null,FILTER_VALIDATE_INT);
        $qtd=filter_var($r["quantidade"] ?? null,FILTER_VALIDATE_INT);
        $turma=trim($r["turma"] ?? "");
        $obs=trim($r["observacao"] ?? "");

        if (!$id || !$professor || !$turma ||
            $aula===false || $aula===null || $aula<0 || $aula>7 ||
            $qtd===false || $qtd===null || $qtd<1 ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)) {
            responder(["ok"=>false,"error"=>"Preencha os dados corretamente."],400);
        }

        $dt=DateTime::createFromFormat("!Y-m-d",$data);
        if (!$dt || $dt->format("Y-m-d")!==$data || $data<date("Y-m-d")) {
            responder(["ok"=>false,"error"=>"Data inválida."],400);
        }

        $conexao->beginTransaction();

        $s=$conexao->prepare(
            "SELECT total FROM agenda_recursos WHERE nome=? FOR UPDATE"
        );
        $s->execute([$recurso]);
        $total=$s->fetchColumn();

        if ($total===false) {
            $conexao->rollBack();
            responder(["ok"=>false,"error"=>"Recurso não encontrado."],400);
        }

        $disponivel=max(0,(int)$total-quebrados($conexao,$recurso));

        $s=$conexao->prepare(
            "SELECT COALESCE(SUM(quantidade),0)
             FROM agenda_reservas
             WHERE recurso=? AND data=? AND aula=?
             AND status IN ('Pendente','Aprovada')"
        );
        $s->execute([$recurso,$data,$aula]);
        $reservado=(int)$s->fetchColumn();

        if ($qtd>$disponivel-$reservado) {
            $conexao->rollBack();
            responder([
                "ok"=>false,
                "error"=>"Quantidade indisponível. Restam ".
                    max(0,$disponivel-$reservado)." unidade(s)."
            ],409);
        }

        $s=$conexao->prepare(
            "INSERT INTO agenda_reservas
            (id,professor,recurso,data,aula,inicio,quantidade,turma,observacao,status)
            VALUES (?,?,?,?,?,?,?,?,?,'Pendente')"
        );

        $s->execute([
            $id,$professor,$recurso,$data,$aula,
            $aulas[$aula],$qtd,$turma,$obs
        ]);

        $conexao->commit();
        responder(["ok"=>true,"message"=>"Reserva cadastrada com sucesso!"]);
    }

    if ($action==="alterarStatus") {
        $id=$dados["id"] ?? "";
        $status=$dados["status"] ?? "";

        if (!$id || !in_array($status,["Aprovada","Recusada","Cancelada"],true)) {
            responder(["ok"=>false,"error"=>"Dados inválidos."],400);
        }

        $s=$conexao->prepare(
            "UPDATE agenda_reservas SET status=?
             WHERE id=? AND status='Pendente'"
        );
        $s->execute([$status,$id]);

        if (!$s->rowCount()) {
            responder(["ok"=>false,"error"=>"Reserva não encontrada ou já processada."],404);
        }
        responder(["ok"=>true]);
    }

    if ($action==="cancelarReserva") {
        $id=$dados["id"] ?? "";
        $professor=trim($dados["professor"] ?? "");

        $s=$conexao->prepare(
            "UPDATE agenda_reservas SET status='Cancelada'
             WHERE id=? AND professor=? AND status='Pendente'"
        );
        $s->execute([$id,$professor]);

        if (!$s->rowCount()) {
            responder(["ok"=>false,"error"=>"Não foi possível cancelar a reserva."],404);
        }
        responder(["ok"=>true]);
    }

    if ($action==="registrarManutencao") {
        $m=$dados["manutencao"] ?? [];

        $id=trim($m["id"] ?? "");
        $recurso=trim($m["recurso"] ?? "");
        $acao=$m["acao"] ?? "";
        $qtd=filter_var($m["quantidade"] ?? null,FILTER_VALIDATE_INT);
        $motivo=trim($m["motivo"] ?? "");
        $responsavel=trim($m["responsavel"] ?? "");

        if (!$id || !$recurso ||
            !in_array($acao,["quebrado","reparado"],true) ||
            $qtd===false || $qtd===null || $qtd<1 ||
            !$motivo || !$responsavel) {
            responder(["ok"=>false,"error"=>"Dados da manutenção inválidos."],400);
        }

        $conexao->beginTransaction();

        $s=$conexao->prepare(
            "SELECT total FROM agenda_recursos WHERE nome=? FOR UPDATE"
        );
        $s->execute([$recurso]);
        $total=$s->fetchColumn();

        if ($total===false) {
            $conexao->rollBack();
            responder(["ok"=>false,"error"=>"Recurso não encontrado."],400);
        }

        $atual=quebrados($conexao,$recurso);

        if (($acao==="quebrado" && $atual+$qtd>(int)$total) ||
            ($acao==="reparado" && $qtd>$atual)) {
            $conexao->rollBack();
            responder(["ok"=>false,"error"=>"Quantidade inválida para a manutenção."],400);
        }

        $s=$conexao->prepare(
            "INSERT INTO agenda_manutencoes
            (id,recurso,acao,quantidade,motivo,responsavel)
            VALUES (?,?,?,?,?,?)"
        );
        $s->execute([$id,$recurso,$acao,$qtd,$motivo,$responsavel]);

        $conexao->commit();
        responder(["ok"=>true]);
    }

    responder(["ok"=>false,"error"=>"Ação desconhecida."],400);

} catch (Throwable $erro) {
    if (isset($conexao) && $conexao->inTransaction()) {
        $conexao->rollBack();
    }
    error_log("Erro API agenda: ".$erro->getMessage());
    responder(["ok"=>false,"error"=>"Erro interno no servidor. Confira o log do PHP."],500);
}
?>