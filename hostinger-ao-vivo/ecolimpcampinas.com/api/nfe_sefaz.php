<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$numero = $input['numero'] ?? null;
if (!$numero) {
    http_response_code(400);
    echo json_encode(['error' => 'Número da nota obrigatório']);
    exit;
}

function onlyDigits($value) {
    return preg_replace('/\D/', '', $value ?? '');
}

function normalizeGtin($value) {
    $digits = onlyDigits($value);
    $len = strlen($digits);
    if (in_array($len, [8, 12, 13, 14], true)) {
        return $digits;
    }
    return 'SEM GTIN';
}

function normalizeFantasia($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/[^\x20-\x7E\x{00A0}-\x{00FF}]/u', '', $text);
    $text = trim((string)$text);
    if ($text === '') {
        return null;
    }
    $text = mb_substr($text, 0, 60, 'UTF-8');
    return $text === '' ? null : $text;
}

function getUfCode($uf) {
    $map = [
        'AC' => '12','AL' => '27','AP' => '16','AM' => '13','BA' => '29','CE' => '23','DF' => '53','ES' => '32','GO' => '52','MA' => '21',
        'MT' => '51','MS' => '50','MG' => '31','PA' => '15','PB' => '25','PR' => '41','PE' => '26','PI' => '22','RJ' => '33','RN' => '24',
        'RS' => '43','RO' => '11','RR' => '14','SC' => '42','SP' => '35','SE' => '28','TO' => '17'
    ];
    return $map[strtoupper($uf ?? '')] ?? null;
}

function calcMod11($chave) {
    $peso = 2;
    $soma = 0;
    for ($i = strlen($chave) - 1; $i >= 0; $i--) {
        $soma += intval($chave[$i]) * $peso;
        $peso = ($peso < 9) ? $peso + 1 : 2;
    }
    $resto = $soma % 11;
    $dv = 11 - $resto;
    if ($dv == 10 || $dv == 11) $dv = 0;
    return (string)$dv;
}

function signNFeXml($xml, $pfxBase64, $senha) {
    $pfx = base64_decode($pfxBase64);
    if (!$pfx) {
        throw new Exception('Certificado A1 inválido');
    }
    $certs = [];
    if (!openssl_pkcs12_read($pfx, $certs, $senha ?? '')) {
        throw new Exception('Senha do certificado inválida ou certificado incompatível');
    }
    $privateKey = $certs['pkey'];
    $publicCert = $certs['cert'];

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = false;
    $doc->loadXML($xml);

    $infNFe = $doc->getElementsByTagName('infNFe')->item(0);
    if (!$infNFe) {
        throw new Exception('infNFe não encontrado');
    }
    $id = $infNFe->getAttribute('Id');
    if (!preg_match('/^NFe\d{44}$/', $id)) {
        throw new Exception('Id da infNFe inválido');
    }

    $canonicalInf = $infNFe->C14N(true, false);
    $digestValue = base64_encode(hash('sha1', $canonicalInf, true));
    error_log('DigestValue gerado: ' . $digestValue);

    $signatureNode = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');
    $signedInfo = $doc->createElement('SignedInfo');
    $canonicalMethod = $doc->createElement('CanonicalizationMethod');
    $canonicalMethod->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
    $signatureMethod = $doc->createElement('SignatureMethod');
    $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
    $reference = $doc->createElement('Reference');
    $reference->setAttribute('URI', '#' . $id);
    $transforms = $doc->createElement('Transforms');
    $transform1 = $doc->createElement('Transform');
    $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
    $transform2 = $doc->createElement('Transform');
    $transform2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
    $transforms->appendChild($transform1);
    $transforms->appendChild($transform2);
    $digestMethod = $doc->createElement('DigestMethod');
    $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
    $digestValueNode = $doc->createElement('DigestValue', $digestValue);

    $reference->appendChild($transforms);
    $reference->appendChild($digestMethod);
    $reference->appendChild($digestValueNode);
    $signedInfo->appendChild($canonicalMethod);
    $signedInfo->appendChild($signatureMethod);
    $signedInfo->appendChild($reference);

    $signatureNode->appendChild($signedInfo);
    $canonicalSignedInfo = $signedInfo->C14N(true, false);
    $signatureValue = '';
    openssl_sign($canonicalSignedInfo, $signatureValue, $privateKey, OPENSSL_ALGO_SHA1);
    $signatureValueNode = $doc->createElement('SignatureValue', base64_encode($signatureValue));
    $signatureNode->appendChild($signatureValueNode);

    $keyInfo = $doc->createElement('KeyInfo');
    $x509Data = $doc->createElement('X509Data');
    $certClean = str_replace(["\r", "\n", "-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----"], '', $publicCert);
    $x509Certificate = $doc->createElement('X509Certificate', $certClean);
    $x509Data->appendChild($x509Certificate);
    $keyInfo->appendChild($x509Data);
    $signatureNode->appendChild($keyInfo);

    $nfeNode = $doc->getElementsByTagName('NFe')->item(0);
    $nfeNode->appendChild($signatureNode);

    return $doc->saveXML();
}

try {
    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        throw new Exception('Configurações não encontradas');
    }

    $stmtNota = $pdo->prepare("SELECT * FROM nfe_notas WHERE numero = ?");
    $stmtNota->execute([$numero]);
    $nota = $stmtNota->fetch(PDO::FETCH_ASSOC);
    if (!$nota) {
        throw new Exception('Nota não encontrada');
    }

    $stmtCliente = $pdo->prepare("SELECT * FROM nfe_clientes WHERE id = ?");
    $stmtCliente->execute([$nota['cliente_id']]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) {
        throw new Exception('Cliente não encontrado');
    }
    $enderecoCliente = $cliente['endereco_json'] ? json_decode($cliente['endereco_json'], true) : [];

    $stmtItens = $pdo->prepare("SELECT * FROM nfe_itens WHERE nfe_numero = ?");
    $stmtItens->execute([$numero]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);
    if (!$itens || count($itens) === 0) {
        throw new Exception('Itens da nota não encontrados');
    }

    $cnpjEmit = onlyDigits($config['cnpj']);
    if (!$cnpjEmit) {
        throw new Exception('CNPJ do emitente não configurado');
    }
    if (strlen($cnpjEmit) !== 14) {
        throw new Exception('CNPJ do emitente inválido');
    }

    $ufEmit = $config['nfe_emitente_uf'] ?? '';
    $cUF = getUfCode($ufEmit);
    if (!$cUF) {
        throw new Exception('UF do emitente inválida');
    }
    $natOp = trim($nota['natureza_operacao'] ?? '');
    if ($natOp === '') {
        throw new Exception('Natureza da operação não informada');
    }
    $emitNome = trim($config['nome_empresa'] ?? '');
    if ($emitNome === '') {
        throw new Exception('Razão social do emitente não configurada');
    }
    $emitIe = onlyDigits($config['nfe_emitente_ie'] ?? '');
    if ($emitIe === '') {
        throw new Exception('IE do emitente não configurada');
    }
    $emitCrt = trim((string)($config['nfe_emitente_crt'] ?? ''));
    if ($emitCrt === '') {
        throw new Exception('CRT do emitente não configurado');
    }
    $emitLogradouro = trim((string)($config['nfe_emitente_logradouro'] ?? ''));
    $emitNumero = trim((string)($config['nfe_emitente_numero'] ?? ''));
    $emitBairro = trim((string)($config['nfe_emitente_bairro'] ?? ''));
    $emitCidade = trim((string)($config['nfe_emitente_cidade'] ?? ''));
    $emitUf = trim((string)($config['nfe_emitente_uf'] ?? ''));
    if ($emitLogradouro === '' || $emitNumero === '' || $emitBairro === '' || $emitCidade === '' || $emitUf === '') {
        throw new Exception('Endereço do emitente incompleto');
    }
    $emitCmun = onlyDigits($config['nfe_emitente_cmun'] ?? '');
    if ($emitCmun === '' || strlen($emitCmun) !== 7) {
        $emitCmun = $cUF . '00000';
    }

    $destRazao = trim((string)($cliente['razao_social'] ?? ''));
    $destCnpj = onlyDigits($cliente['cnpj'] ?? '');
    if ($destRazao === '' || $destCnpj === '') {
        throw new Exception('Dados do destinatário incompletos');
    }
    if (strlen($destCnpj) !== 14) {
        throw new Exception('CNPJ do destinatário inválido');
    }
    $destLogradouro = trim((string)($enderecoCliente['rua'] ?? ''));
    $destNumero = trim((string)($enderecoCliente['numero'] ?? ''));
    $destBairro = trim((string)($enderecoCliente['bairro'] ?? ''));
    $destCidade = trim((string)($enderecoCliente['cidade'] ?? ''));
    $destUf = trim((string)($enderecoCliente['uf'] ?? ''));
    if ($destLogradouro === '' || $destNumero === '' || $destBairro === '' || $destCidade === '' || $destUf === '') {
        throw new Exception('Endereço do destinatário incompleto');
    }
    $destCmun = onlyDigits($enderecoCliente['cmun'] ?? '');
    if ($destCmun === '' || strlen($destCmun) !== 7) {
        $destCmun = $emitCmun;
    }
    $tpAmb = ($config['nfe_ambiente'] ?? 'homologacao') === 'producao' ? '1' : '2';
    $tpNF = ($nota['tipo_nota'] ?? 'venda') === 'devolucao' ? '0' : '1';
    $mod = '55';
    $serie = str_pad((string)$nota['serie'], 3, '0', STR_PAD_LEFT);
    $nNF = str_pad((string)$nota['numero'], 9, '0', STR_PAD_LEFT);
    $dhEmi = date('c');
    $dhSaiEnt = $dhEmi;
    $cNF = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
    $aamm = date('ym');
    $tpEmis = '1';
    $chaveBase = $cUF . $aamm . $cnpjEmit . $mod . $serie . $nNF . $tpEmis . $cNF;
    $cDV = calcMod11($chaveBase);
    $chave = $chaveBase . $cDV;

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = false;
    $nfe = $doc->createElement('NFe');
    $nfe->setAttribute('xmlns', 'http://www.portalfiscal.inf.br/nfe');
    $infNFe = $doc->createElement('infNFe');
    $infNFe->setAttribute('Id', 'NFe' . $chave);
    $infNFe->setAttribute('versao', '4.00');

    $ide = $doc->createElement('ide');
    $ide->appendChild($doc->createElement('cUF', $cUF));
    $ide->appendChild($doc->createElement('cNF', $cNF));
    $ide->appendChild($doc->createElement('natOp', $natOp));
    $ide->appendChild($doc->createElement('mod', $mod));
    $ide->appendChild($doc->createElement('serie', (string)$nota['serie']));
    $ide->appendChild($doc->createElement('nNF', (string)$nota['numero']));
    $ide->appendChild($doc->createElement('dhEmi', $dhEmi));
    $ide->appendChild($doc->createElement('dhSaiEnt', $dhSaiEnt));
    $ide->appendChild($doc->createElement('tpNF', $tpNF));
    $idDest = '1';
    if ($destUf && $ufEmit && strtoupper($destUf) !== strtoupper($ufEmit)) {
        $idDest = '2';
    }
    $ide->appendChild($doc->createElement('idDest', $idDest));
    $ide->appendChild($doc->createElement('cMunFG', $emitCmun));
    $ide->appendChild($doc->createElement('tpImp', '1'));
    $ide->appendChild($doc->createElement('tpEmis', $tpEmis));
    $ide->appendChild($doc->createElement('cDV', $cDV));
    $ide->appendChild($doc->createElement('tpAmb', $tpAmb));
    $ide->appendChild($doc->createElement('finNFe', '1'));
    $indFinal = !empty($cliente['contribuinte_icms']) ? '0' : '1';
    $ide->appendChild($doc->createElement('indFinal', $indFinal));
    $ide->appendChild($doc->createElement('indPres', '1'));
    $ide->appendChild($doc->createElement('procEmi', '0'));
    $ide->appendChild($doc->createElement('verProc', '1.0'));
    $infNFe->appendChild($ide);

    $emit = $doc->createElement('emit');
    $emit->appendChild($doc->createElement('CNPJ', $cnpjEmit));
    $emit->appendChild($doc->createElement('xNome', $emitNome));
    $fantasia = normalizeFantasia($config['nfe_emitente_fantasia'] ?? null);
    if ($fantasia !== null) {
        $emit->appendChild($doc->createElement('xFant', $fantasia));
    }
    $enderEmit = $doc->createElement('enderEmit');
    $enderEmit->appendChild($doc->createElement('xLgr', $emitLogradouro));
    $enderEmit->appendChild($doc->createElement('nro', $emitNumero));
    if (!empty($config['nfe_emitente_complemento'])) {
        $enderEmit->appendChild($doc->createElement('xCpl', $config['nfe_emitente_complemento']));
    }
    $enderEmit->appendChild($doc->createElement('xBairro', $emitBairro));
    $enderEmit->appendChild($doc->createElement('cMun', $emitCmun));
    $enderEmit->appendChild($doc->createElement('xMun', $emitCidade));
    $enderEmit->appendChild($doc->createElement('UF', $emitUf));
    $emitCep = onlyDigits($config['nfe_emitente_cep'] ?? '');
    if ($emitCep !== '') {
        $enderEmit->appendChild($doc->createElement('CEP', $emitCep));
    }
    $enderEmit->appendChild($doc->createElement('cPais', '1058'));
    $enderEmit->appendChild($doc->createElement('xPais', 'BRASIL'));
    if (!empty($config['nfe_emitente_fone'])) {
        $enderEmit->appendChild($doc->createElement('fone', onlyDigits($config['nfe_emitente_fone'])));
    }
    $emit->appendChild($enderEmit);
    $emit->appendChild($doc->createElement('IE', $emitIe));
    if (!empty($config['nfe_emitente_im'])) {
        $emit->appendChild($doc->createElement('IM', $config['nfe_emitente_im']));
    }
    $emit->appendChild($doc->createElement('CRT', $emitCrt));
    $infNFe->appendChild($emit);

    $dest = $doc->createElement('dest');
    $dest->appendChild($doc->createElement('CNPJ', $destCnpj));
    $dest->appendChild($doc->createElement('xNome', $destRazao));
    $enderDest = $doc->createElement('enderDest');
    $enderDest->appendChild($doc->createElement('xLgr', $destLogradouro));
    $enderDest->appendChild($doc->createElement('nro', $destNumero));
    if (!empty($enderecoCliente['complemento'])) {
        $enderDest->appendChild($doc->createElement('xCpl', $enderecoCliente['complemento']));
    }
    $enderDest->appendChild($doc->createElement('xBairro', $destBairro));
    $enderDest->appendChild($doc->createElement('cMun', $destCmun));
    $enderDest->appendChild($doc->createElement('xMun', $destCidade));
    $enderDest->appendChild($doc->createElement('UF', $destUf));
    $destCep = onlyDigits($enderecoCliente['cep'] ?? '');
    if ($destCep !== '') {
        $enderDest->appendChild($doc->createElement('CEP', $destCep));
    }
    $enderDest->appendChild($doc->createElement('cPais', '1058'));
    $enderDest->appendChild($doc->createElement('xPais', 'BRASIL'));
    if (!empty($cliente['telefone'])) {
        $enderDest->appendChild($doc->createElement('fone', onlyDigits($cliente['telefone'])));
    }
    $dest->appendChild($enderDest);
    $dest->appendChild($doc->createElement('indIEDest', !empty($cliente['contribuinte_icms']) ? '1' : '9'));
    if (!empty($cliente['ie'])) {
        $dest->appendChild($doc->createElement('IE', onlyDigits($cliente['ie'])));
    }
    $infNFe->appendChild($dest);

    $vBC = 0;
    $vICMS = 0;
    $vIPI = 0;
    $vPIS = 0;
    $vCOFINS = 0;
    $vProd = 0;
    $vTotTrib = 0;

    foreach ($itens as $index => $item) {
        $det = $doc->createElement('det');
        $det->setAttribute('nItem', (string)($index + 1));
        $prod = $doc->createElement('prod');
        $prod->appendChild($doc->createElement('cProd', $item['produto_id']));
        $gtin = normalizeGtin($item['gtin'] ?? '');
        $prod->appendChild($doc->createElement('cEAN', $gtin));
        $prod->appendChild($doc->createElement('xProd', $item['descricao']));
        $prod->appendChild($doc->createElement('NCM', $item['ncm']));
        $prod->appendChild($doc->createElement('CFOP', $item['cfop']));
        $prod->appendChild($doc->createElement('uCom', $item['unidade_fiscal']));
        $prod->appendChild($doc->createElement('qCom', number_format($item['quantidade'], 4, '.', '')));
        $prod->appendChild($doc->createElement('vUnCom', number_format($item['valor_unitario'], 4, '.', '')));
        $prod->appendChild($doc->createElement('vProd', number_format($item['total'], 2, '.', '')));
        $prod->appendChild($doc->createElement('cEANTrib', $gtin));
        $prod->appendChild($doc->createElement('uTrib', $item['unidade_fiscal']));
        $prod->appendChild($doc->createElement('qTrib', number_format($item['quantidade'], 4, '.', '')));
        $prod->appendChild($doc->createElement('vUnTrib', number_format($item['valor_unitario'], 4, '.', '')));
        $prod->appendChild($doc->createElement('indTot', '1'));
        $det->appendChild($prod);

        $imposto = $doc->createElement('imposto');
        $baseItem = (float)$item['total'];
        $icms = $doc->createElement('ICMS');
        $orig = $item['origem'] ?? 0;
        $crt = (string)$emitCrt;
        $csosn = onlyDigits($item['cst_csosn'] ?? '');
        if ($crt === '1' || $crt === '2') {
            if ($csosn === '' || $csosn === '000') {
                throw new Exception('CSOSN inválido para CRT ' . $crt);
            }
            if ($csosn === '101') {
                $icmsTag = $doc->createElement('ICMSSN101');
                $icmsTag->appendChild($doc->createElement('orig', $orig));
                $icmsTag->appendChild($doc->createElement('CSOSN', $csosn));
                $icmsPercent = ($item['icms'] ?? 0) > 0 && $baseItem > 0 ? (($item['icms'] / $baseItem) * 100) : 0;
                $icmsTag->appendChild($doc->createElement('pCredSN', number_format($icmsPercent, 2, '.', '')));
                $icmsTag->appendChild($doc->createElement('vCredICMSSN', number_format($item['icms'] ?? 0, 2, '.', '')));
            } elseif ($csosn === '102') {
                $icmsTag = $doc->createElement('ICMSSN102');
                $icmsTag->appendChild($doc->createElement('orig', $orig));
                $icmsTag->appendChild($doc->createElement('CSOSN', $csosn));
            } else {
                throw new Exception('CSOSN não suportado para CRT ' . $crt . ': ' . $csosn);
            }
        } elseif ($crt === '3') {
            if ($csosn === '') {
                $csosn = '00';
            }
            if (strlen($csosn) !== 2) {
                throw new Exception('CST inválido para CRT 3: ' . $csosn);
            }
            if ($csosn === '00') {
                $icmsTag = $doc->createElement('ICMS00');
                $icmsTag->appendChild($doc->createElement('orig', $orig));
                $icmsTag->appendChild($doc->createElement('CST', $csosn));
                $icmsTag->appendChild($doc->createElement('modBC', '3'));
                $icmsTag->appendChild($doc->createElement('vBC', number_format($item['total'], 2, '.', '')));
                $icmsPercent = ($item['icms'] ?? 0) > 0 && $baseItem > 0 ? (($item['icms'] / $baseItem) * 100) : 0;
                $icmsTag->appendChild($doc->createElement('pICMS', number_format($icmsPercent, 2, '.', '')));
                $icmsTag->appendChild($doc->createElement('vICMS', number_format($item['icms'] ?? 0, 2, '.', '')));
            } elseif (in_array($csosn, ['40', '41', '50'], true)) {
                $icmsTag = $doc->createElement('ICMS40');
                $icmsTag->appendChild($doc->createElement('orig', $orig));
                $icmsTag->appendChild($doc->createElement('CST', $csosn));
            } else {
                throw new Exception('CST não suportado para CRT 3: ' . $csosn);
            }
        } else {
            throw new Exception('CRT inválido para ICMS: ' . $crt);
        }
        $icms->appendChild($icmsTag);
        $imposto->appendChild($icms);

        $pis = $doc->createElement('PIS');
        $pisAliq = $doc->createElement('PISAliq');
        $pisAliq->appendChild($doc->createElement('CST', '01'));
        $pisAliq->appendChild($doc->createElement('vBC', number_format($item['total'], 2, '.', '')));
        $pisPercent = ($item['pis'] ?? 0) > 0 && $baseItem > 0 ? (($item['pis'] / $baseItem) * 100) : 0;
        $pisAliq->appendChild($doc->createElement('pPIS', number_format($pisPercent, 2, '.', '')));
        $pisAliq->appendChild($doc->createElement('vPIS', number_format($item['pis'] ?? 0, 2, '.', '')));
        $pis->appendChild($pisAliq);
        $imposto->appendChild($pis);

        $cofins = $doc->createElement('COFINS');
        $cofinsAliq = $doc->createElement('COFINSAliq');
        $cofinsAliq->appendChild($doc->createElement('CST', '01'));
        $cofinsAliq->appendChild($doc->createElement('vBC', number_format($item['total'], 2, '.', '')));
        $cofinsPercent = ($item['cofins'] ?? 0) > 0 && $baseItem > 0 ? (($item['cofins'] / $baseItem) * 100) : 0;
        $cofinsAliq->appendChild($doc->createElement('pCOFINS', number_format($cofinsPercent, 2, '.', '')));
        $cofinsAliq->appendChild($doc->createElement('vCOFINS', number_format($item['cofins'] ?? 0, 2, '.', '')));
        $cofins->appendChild($cofinsAliq);
        $imposto->appendChild($cofins);

        $itemIpi = (float)($item['ipi'] ?? 0);
        if ($itemIpi > 0) {
            $ipi = $doc->createElement('IPI');
            $ipiTrib = $doc->createElement('IPITrib');
            $ipiTrib->appendChild($doc->createElement('CST', '50'));
            $ipiTrib->appendChild($doc->createElement('vBC', number_format($item['total'], 2, '.', '')));
            $ipiPercent = $baseItem > 0 ? (($itemIpi / $baseItem) * 100) : 0;
            $ipiTrib->appendChild($doc->createElement('pIPI', number_format($ipiPercent, 2, '.', '')));
            $ipiTrib->appendChild($doc->createElement('vIPI', number_format($itemIpi, 2, '.', '')));
            $ipi->appendChild($ipiTrib);
            $imposto->appendChild($ipi);
        }

        $vTotTribItem = (float)($item['icms'] ?? 0) + (float)($item['ipi'] ?? 0) + (float)($item['pis'] ?? 0) + (float)($item['cofins'] ?? 0);
        $imposto->appendChild($doc->createElement('vTotTrib', number_format($vTotTribItem, 2, '.', '')));
        $det->appendChild($imposto);
        $infNFe->appendChild($det);

        $vBC += $item['total'];
        $vICMS += $item['icms'] ?? 0;
        $vIPI += $item['ipi'] ?? 0;
        $vPIS += $item['pis'] ?? 0;
        $vCOFINS += $item['cofins'] ?? 0;
        $vProd += $item['total'];
        $vTotTrib += $vTotTribItem;
    }

    $total = $doc->createElement('total');
    $icmsTot = $doc->createElement('ICMSTot');
    $icmsTot->appendChild($doc->createElement('vBC', number_format($vBC, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vICMS', number_format($vICMS, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vICMSDeson', '0.00'));
    $icmsTot->appendChild($doc->createElement('vFCPUFDest', '0.00'));
    $icmsTot->appendChild($doc->createElement('vICMSUFDest', '0.00'));
    $icmsTot->appendChild($doc->createElement('vICMSUFRemet', '0.00'));
    $icmsTot->appendChild($doc->createElement('vFCP', '0.00'));
    $icmsTot->appendChild($doc->createElement('vBCST', '0.00'));
    $icmsTot->appendChild($doc->createElement('vST', '0.00'));
    $icmsTot->appendChild($doc->createElement('vFCPST', '0.00'));
    $icmsTot->appendChild($doc->createElement('vFCPSTRet', '0.00'));
    $icmsTot->appendChild($doc->createElement('vProd', number_format($vProd, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vFrete', '0.00'));
    $icmsTot->appendChild($doc->createElement('vSeg', '0.00'));
    $icmsTot->appendChild($doc->createElement('vDesc', '0.00'));
    $icmsTot->appendChild($doc->createElement('vII', '0.00'));
    $icmsTot->appendChild($doc->createElement('vIPI', number_format($vIPI, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vIPIDevol', '0.00'));
    $icmsTot->appendChild($doc->createElement('vPIS', number_format($vPIS, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vCOFINS', number_format($vCOFINS, 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vOutro', '0.00'));
    $icmsTot->appendChild($doc->createElement('vNF', number_format($nota['total'], 2, '.', '')));
    $icmsTot->appendChild($doc->createElement('vTotTrib', number_format($vTotTrib, 2, '.', '')));
    $total->appendChild($icmsTot);
    $infNFe->appendChild($total);

    $transp = $doc->createElement('transp');
    $transp->appendChild($doc->createElement('modFrete', '9'));
    $infNFe->appendChild($transp);

    $nfe->appendChild($infNFe);
    $doc->appendChild($nfe);
    $xml = $doc->saveXML();
    error_log('XML antes de assinar: ' . $xml);
    $schemaPath = __DIR__ . '/schemas/nfe_v4.00.xsd';
    if (file_exists($schemaPath)) {
        $schemaDoc = new DOMDocument();
        if (!$schemaDoc->loadXML($xml)) {
            throw new Exception('Falha ao carregar XML para validação');
        }
        libxml_use_internal_errors(true);
        $schemaValid = $schemaDoc->schemaValidate($schemaPath);
        $schemaErrors = libxml_get_errors();
        libxml_clear_errors();
        if (!$schemaValid) {
            $detail = '';
            if (!empty($schemaErrors)) {
                $first = $schemaErrors[0];
                $detail = trim($first->message ?? '');
                if (!empty($first->line)) {
                    $detail = $detail . ' (linha ' . $first->line . ')';
                }
            }
            $msg = 'XML inválido conforme schema XSD';
            if ($detail !== '') {
                $msg .= ': ' . $detail;
            }
            throw new Exception($msg);
        }
    } else {
        error_log('Schema XSD não encontrado, validação ignorada: ' . $schemaPath);
    }

    $certTipo = $config['nfe_cert_tipo'] ?? 'A1';
    if ($certTipo === 'A1') {
        $xmlAssinado = signNFeXml($xml, $config['nfe_cert_pfx_base64'], $config['nfe_cert_senha']);
    } else {
        $gateway = $config['nfe_a3_gateway_url'] ?? '';
        if (!$gateway) {
            throw new Exception('Gateway A3 não configurado');
        }
        $ch = curl_init($gateway);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'xml' => $xml,
            'pin' => $config['nfe_a3_pin'] ?? '',
            'label' => $config['nfe_a3_label'] ?? '',
            'pkcs11' => $config['nfe_a3_pkcs11_lib'] ?? ''
        ]));
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new Exception('Falha no gateway A3');
        }
        $gatewayData = json_decode($resp, true);
        if (!is_array($gatewayData) || empty($gatewayData['xml_assinado'])) {
            throw new Exception('Resposta inválida do gateway A3');
        }
        $xmlAssinado = $gatewayData['xml_assinado'];
    }

    $envi = new DOMDocument('1.0', 'UTF-8');
    $envi->preserveWhiteSpace = false;
    $envi->formatOutput = false;
    $enviNFe = $envi->createElement('enviNFe');
    $enviNFe->setAttribute('xmlns', 'http://www.portalfiscal.inf.br/nfe');
    $enviNFe->setAttribute('versao', '4.00');
    $enviNFe->appendChild($envi->createElement('idLote', '000000000000001'));
    $enviNFe->appendChild($envi->createElement('indSinc', '1'));
    $xmlTemp = new DOMDocument();
    $xmlTemp->loadXML($xmlAssinado);
    $nfeNode = $envi->importNode($xmlTemp->documentElement, true);
    $enviNFe->appendChild($nfeNode);
    $envi->appendChild($enviNFe);
    $enviXml = $envi->saveXML();

    $url = ($config['nfe_ambiente'] ?? 'homologacao') === 'producao'
        ? ($config['nfe_autorizador_url_producao'] ?? '')
        : ($config['nfe_autorizador_url_homologacao'] ?? '');
    if (!$url) {
        throw new Exception('URL do autorizador não configurada');
    }

    $soap = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">' .
        '<soap12:Header>' .
        '<nfeCabecMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4">' .
        '<cUF>' . $cUF . '</cUF>' .
        '<versaoDados>4.00</versaoDados>' .
        '</nfeCabecMsg>' .
        '</soap12:Header>' .
        '<soap12:Body>' .
        '<nfeAutorizacaoLote xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4">' .
        $enviXml .
        '</nfeAutorizacaoLote>' .
        '</soap12:Body>' .
        '</soap12:Envelope>';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4/nfeAutorizacaoLote"'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $soap);
    $sslInsecure = !empty($config['nfe_ssl_insecure']);
    if ($sslInsecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    if ($certTipo === 'A1') {
        $tmpPfx = tempnam(sys_get_temp_dir(), 'pfx');
        file_put_contents($tmpPfx, base64_decode($config['nfe_cert_pfx_base64']));
        curl_setopt($ch, CURLOPT_SSLCERT, $tmpPfx);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $config['nfe_cert_senha'] ?? '');
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($response) {
        error_log('XML de Resposta da SEFAZ: ' . $response);
    }
    if (isset($tmpPfx)) {
        @unlink($tmpPfx);
    }
    if ($curlErr) {
        if (stripos($curlErr, 'issuer certificate') !== false || stripos($curlErr, 'unable to get local issuer certificate') !== false) {
            throw new Exception('Erro SSL: certificado do autorizador não confiável no servidor. Ative "Ignorar validação SSL" nas configurações ou instale o CA bundle no servidor.');
        }
        throw new Exception($curlErr);
    }

    $cStat = null;
    $xMotivo = null;
    $nProt = null;
    $nRec = null;
    $xmlFinal = $xmlAssinado;
    if ($response) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($response);
        libxml_clear_errors();
        if ($loaded) {
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('soap12', 'http://www.w3.org/2003/05/soap-envelope');
            $xpath->registerNamespace('ws', 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4');
            $xpath->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $protNode = $xpath->query("//*[local-name()='protNFe']")->item(0);
            if ($protNode) {
                $xmlSignedDoc = new DOMDocument();
                if ($xmlSignedDoc->loadXML($xmlAssinado)) {
                    $procDoc = new DOMDocument('1.0', 'UTF-8');
                    $procDoc->preserveWhiteSpace = false;
                    $procDoc->formatOutput = false;
                    $nfeProc = $procDoc->createElement('nfeProc');
                    $nfeProc->setAttribute('xmlns', 'http://www.portalfiscal.inf.br/nfe');
                    $nfeProc->setAttribute('versao', '4.00');
                    $importNfe = $procDoc->importNode($xmlSignedDoc->documentElement, true);
                    $importProt = $procDoc->importNode($protNode, true);
                    $nfeProc->appendChild($importNfe);
                    $nfeProc->appendChild($importProt);
                    $procDoc->appendChild($nfeProc);
                    $xmlFinal = $procDoc->saveXML();
                }
            }
            $retNode = $xpath->query("//*[local-name()='retEnviNFe']")->item(0);
            if ($retNode) {
                $cStatNode = $xpath->query(".//*[local-name()='cStat']", $retNode)->item(0);
                if ($cStatNode) {
                    $val = trim($cStatNode->textContent);
                    if ($val !== '') { $cStat = $val; }
                }
                $xMotivoNode = $xpath->query(".//*[local-name()='xMotivo']", $retNode)->item(0);
                if ($xMotivoNode) {
                    $val = trim($xMotivoNode->textContent);
                    if ($val !== '') { $xMotivo = $val; }
                }
                $infRecNode = $xpath->query(".//*[local-name()='infRec']", $retNode)->item(0);
                if ($infRecNode) {
                    $nRecNode = $xpath->query(".//*[local-name()='nRec']", $infRecNode)->item(0);
                    if ($nRecNode) {
                        $val = trim($nRecNode->textContent);
                        if ($val !== '') { $nRec = $val; }
                    }
                }
                $infProtNode = $xpath->query(".//*[local-name()='infProt']", $retNode)->item(0);
                if ($infProtNode) {
                    $csNode = $xpath->query(".//*[local-name()='cStat']", $infProtNode)->item(0);
                    if ($csNode) {
                        $val = trim($csNode->textContent);
                        if ($val !== '') { $cStat = $val; }
                    }
                    $xmNode = $xpath->query(".//*[local-name()='xMotivo']", $infProtNode)->item(0);
                    if ($xmNode) {
                        $val = trim($xmNode->textContent);
                        if ($val !== '') { $xMotivo = $val; }
                    }
                    $npNode = $xpath->query(".//*[local-name()='nProt']", $infProtNode)->item(0);
                    if ($npNode) {
                        $val = trim($npNode->textContent);
                        if ($val !== '') { $nProt = $val; }
                    }
                }
            } else {
                $infProtNode = $xpath->query("//*[local-name()='infProt']")->item(0);
                if ($infProtNode) {
                    $csNode = $xpath->query(".//*[local-name()='cStat']", $infProtNode)->item(0);
                    if ($csNode) {
                        $val = trim($csNode->textContent);
                        if ($val !== '') { $cStat = $val; }
                    }
                    $xmNode = $xpath->query(".//*[local-name()='xMotivo']", $infProtNode)->item(0);
                    if ($xmNode) {
                        $val = trim($xmNode->textContent);
                        if ($val !== '') { $xMotivo = $val; }
                    }
                    $npNode = $xpath->query(".//*[local-name()='nProt']", $infProtNode)->item(0);
                    if ($npNode) {
                        $val = trim($npNode->textContent);
                        if ($val !== '') { $nProt = $val; }
                    }
                }
            }
        }
    }

    // If protocol not present but we have receipt number, poll retAutorizacao
    if (!$nProt && $nRec) {
        $retSoapBody = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            . '<soap12:Header>'
            . '<nfeCabecMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRetAutorizacao4">'
            . '<cUF>' . $cUF . '</cUF>'
            . '<versaoDados>4.00</versaoDados>'
            . '</nfeCabecMsg>'
            . '</soap12:Header>'
            . '<soap12:Body>'
            . '<nfeRetAutorizacao xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRetAutorizacao4">'
            . '<consReciNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            . '<tpAmb>' . $tpAmb . '</tpAmb>'
            . '<nRec>' . htmlspecialchars($nRec, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</nRec>'
            . '</consReciNFe>'
            . '</nfeRetAutorizacao>'
            . '</soap12:Body>'
            . '</soap12:Envelope>';

        $maxTries = 5;
        for ($i = 0; $i < $maxTries && !$nProt; $i++) {
            $ch2 = curl_init($url);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                'Content-Type: application/soap+xml; charset=utf-8; action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRetAutorizacao4/nfeRetAutorizacao"'
            ]);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $retSoapBody);
            if ($sslInsecure) {
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
            } else {
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
            }
            $resp2 = curl_exec($ch2);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            if ($resp2 && $httpCode2 === 200) {
                $doc2 = new DOMDocument();
                libxml_use_internal_errors(true);
                $loaded2 = $doc2->loadXML($resp2);
                libxml_clear_errors();
                if ($loaded2) {
                    $xp2 = new DOMXPath($doc2);
                    $protNode = $xp2->query("//*[local-name()='infProt']")->item(0);
                    if ($protNode) {
                        $infProt2 = simplexml_import_dom($protNode);
                        if ($infProt2) {
                            $cs2 = (string)$infProt2->cStat;
                            $xm2 = (string)$infProt2->xMotivo;
                            $np2 = (string)$infProt2->nProt;
                            if ($cs2 !== '') { $cStat = $cs2; }
                            if ($xm2 !== '') { $xMotivo = $xm2; }
                            if ($np2 !== '') { $nProt = $np2; }
                        }
                    }
                }
            }
            if (!$nProt) {
                usleep(500000); // 500ms
            }
        }
    }

    $stmtUpd = $pdo->prepare("UPDATE nfe_notas SET sefaz_chave = ?, sefaz_protocolo = ?, sefaz_status = ?, sefaz_mensagem = ?, sefaz_retorno = ?, xml = ? WHERE numero = ?");
    $stmtUpd->execute([$chave, $nProt, $cStat, $xMotivo, $response, $xmlFinal, $numero]);

    echo json_encode([
        'numero' => (int)$numero,
        'chave' => $chave,
        'status' => $cStat,
        'mensagem' => $xMotivo,
        'protocolo' => $nProt,
        'http_code' => $httpCode,
        'retorno' => $response,
        'recibo' => $nRec
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}