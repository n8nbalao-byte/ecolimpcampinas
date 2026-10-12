<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

$products = [
    // Cozinha (Kitchen)
    ['nome' => 'Detergente Limpol Neutro 500ml', 'categoria' => 'cozinha', 'preco' => 2.99, 'descricao' => 'Eficiente na remoção de gorduras.'],
    ['nome' => 'Detergente Ypê Coco 500ml', 'categoria' => 'cozinha', 'preco' => 3.20, 'descricao' => 'Toque suave e natural.'],
    ['nome' => 'Detergente Limão 5L (Galão)', 'categoria' => 'cozinha', 'preco' => 25.90, 'descricao' => 'Economia para grandes volumes de louça.'],
    ['nome' => 'Desengordurante Veja Cozinha 500ml', 'categoria' => 'cozinha', 'preco' => 8.50, 'descricao' => 'Remove gordura queimada sem esforço.'],
    ['nome' => 'Esponja Dupla Face (Pct 4un)', 'categoria' => 'cozinha', 'preco' => 5.00, 'descricao' => 'Durabilidade e eficiência.'],
    ['nome' => 'Lã de Aço Bombril', 'categoria' => 'cozinha', 'preco' => 3.50, 'descricao' => 'Pacote com 8 unidades.'],
    ['nome' => 'Sabão em Pasta 500g', 'categoria' => 'cozinha', 'preco' => 6.90, 'descricao' => 'Brilho intenso para alumínios.'],
    ['nome' => 'Limpa Inox Spray 300ml', 'categoria' => 'cozinha', 'preco' => 15.00, 'descricao' => 'Protege e dá brilho em superfícies de inox.'],
    ['nome' => 'Papel Toalha Snob (2 rolos)', 'categoria' => 'cozinha', 'preco' => 6.50, 'descricao' => 'Alta absorção e resistência.'],
    ['nome' => 'Detergente Máquina de Lavar Louças 1kg', 'categoria' => 'cozinha', 'preco' => 35.00, 'descricao' => 'Pó para máquinas automáticas.'],

    // Banheiro (Bathroom)
    ['nome' => 'Água Sanitária 5L (Galão)', 'categoria' => 'banheiro', 'preco' => 15.90, 'descricao' => 'Desinfecção total e economia.'],
    ['nome' => 'Desinfetante Pinho Sol 1L', 'categoria' => 'banheiro', 'preco' => 9.90, 'descricao' => 'Mata 99,9% das bactérias.'],
    ['nome' => 'Limpa Limo Veja com Cloro 500ml', 'categoria' => 'banheiro', 'preco' => 12.50, 'descricao' => 'Remove o limo dos azulejos e rejuntes.'],
    ['nome' => 'Pedra Sanitária (Cartela 3un)', 'categoria' => 'banheiro', 'preco' => 5.50, 'descricao' => 'Mantém o vaso perfumado.'],
    ['nome' => 'Gel Adesivo Pato', 'categoria' => 'banheiro', 'preco' => 14.00, 'descricao' => 'Limpeza contínua a cada descarga.'],
    ['nome' => 'Tira Limo Pós Obra 5L', 'categoria' => 'banheiro', 'preco' => 45.00, 'descricao' => 'Removedor de resíduos de cimento e rejunte.'],
    ['nome' => 'Limpador de Box Start 500ml', 'categoria' => 'banheiro', 'preco' => 18.00, 'descricao' => 'Remove manchas de gordura e sabão do vidro.'],
    ['nome' => 'Pastilha para Caixa Acoplada', 'categoria' => 'banheiro', 'preco' => 8.00, 'descricao' => 'Água azul e limpeza constante.'],
    ['nome' => 'Escova Sanitária com Suporte', 'categoria' => 'banheiro', 'preco' => 15.00, 'descricao' => 'Higiene e praticidade.'],
    ['nome' => 'Desentupidor de Pia e Ralo Diabo Verde', 'categoria' => 'banheiro', 'preco' => 22.00, 'descricao' => 'Dissolve gorduras e resíduos orgânicos.'],

    // Roupas (Laundry - Detergents/Soaps)
    ['nome' => 'Sabão Líquido Omo Lavagem Perfeita 3L', 'categoria' => 'roupas', 'preco' => 32.90, 'descricao' => 'Remove as manchas mais difíceis.'],
    ['nome' => 'Sabão em Pó Tixan Ypê 1.6kg', 'categoria' => 'roupas', 'preco' => 18.90, 'descricao' => 'Rende muito e limpa bem.'],
    ['nome' => 'Sabão de Coco em Pedra (Pct 5un)', 'categoria' => 'roupas', 'preco' => 12.00, 'descricao' => 'Ideal para roupas delicadas.'],
    ['nome' => 'Sabão Líquido Galão 5L', 'categoria' => 'roupas', 'preco' => 35.00, 'descricao' => 'Economia para lavanderias e grandes famílias.'],
    ['nome' => 'Tira Manchas Vanish Pó 450g', 'categoria' => 'roupas', 'preco' => 25.00, 'descricao' => 'Potencializador de lavagem sem cloro.'],
    ['nome' => 'Alvejante sem Cloro 2L', 'categoria' => 'roupas', 'preco' => 14.00, 'descricao' => 'Seguro para roupas coloridas.'],
    ['nome' => 'Sabão Líquido para Roupas Escuras 2L', 'categoria' => 'roupas', 'preco' => 22.00, 'descricao' => 'Preserva a cor preta por mais tempo.'],
    ['nome' => 'Sabão Líquido Hipoalergênico 2L', 'categoria' => 'roupas', 'preco' => 28.00, 'descricao' => 'Para roupas de bebê e peles sensíveis.'],
    ['nome' => 'Kit Lava Roupas (Pó + Amaciante)', 'categoria' => 'roupas', 'preco' => 45.00, 'descricao' => 'Combo completo para sua lavanderia.'],
    ['nome' => 'Sabão em Pó Brilhante 3kg', 'categoria' => 'roupas', 'preco' => 29.90, 'descricao' => 'Brancura impecável.'],

    // Limpeza Geral (General Cleaning)
    ['nome' => 'Álcool Líquido 70% 1L', 'categoria' => 'limpeza_geral', 'preco' => 8.90, 'descricao' => 'Desinfecção eficaz de superfícies.'],
    ['nome' => 'Álcool Perfumado Eucalipto 1L', 'categoria' => 'limpeza_geral', 'preco' => 9.50, 'descricao' => 'Limpa e perfuma.'],
    ['nome' => 'Cloro Ativo 5L (Galão)', 'categoria' => 'limpeza_geral', 'preco' => 18.00, 'descricao' => 'Para quintais e calçadas.'],
    ['nome' => 'Removedor de Cera 1L', 'categoria' => 'limpeza_geral', 'preco' => 15.00, 'descricao' => 'Remove acúmulo de ceras antigas.'],
    ['nome' => 'Limpa Pedras Pós Obra 5L', 'categoria' => 'limpeza_geral', 'preco' => 38.00, 'descricao' => 'Ácido para limpeza de pedras rústicas.'],
    ['nome' => 'Querosene 1L', 'categoria' => 'limpeza_geral', 'preco' => 12.00, 'descricao' => 'Remove graxa e tintas.'],
    ['nome' => 'Solvente Aguarrás 900ml', 'categoria' => 'limpeza_geral', 'preco' => 14.00, 'descricao' => 'Para diluição de tintas e limpeza.'],
    ['nome' => 'Desinfetante Hospitalar 5L', 'categoria' => 'limpeza_geral', 'preco' => 42.00, 'descricao' => 'Alto nível de desinfecção.'],
    ['nome' => 'Inseticida Aerosol Multi 300ml', 'categoria' => 'limpeza_geral', 'preco' => 11.90, 'descricao' => 'Contra moscas, baratas e mosquitos.'],
    ['nome' => 'Naftalina (Pacote)', 'categoria' => 'limpeza_geral', 'preco' => 6.00, 'descricao' => 'Protege armários contra traças.'],

    // Pisos (Floors)
    ['nome' => 'Limpa Pisos Destac Laminados 750ml', 'categoria' => 'pisos', 'preco' => 16.00, 'descricao' => 'Brilho e proteção para laminados.'],
    ['nome' => 'Limpa Pisos Cerâmicas e Porcelanatos 2L', 'categoria' => 'pisos', 'preco' => 18.00, 'descricao' => 'Limpeza sem embaçar.'],
    ['nome' => 'Cera Líquida Incolor 5L (Galão)', 'categoria' => 'pisos', 'preco' => 35.00, 'descricao' => 'Brilho intenso e duradouro.'],
    ['nome' => 'Cera Autobrilho Ardósia 1L', 'categoria' => 'pisos', 'preco' => 14.00, 'descricao' => 'Especial para pisos de ardósia.'],
    ['nome' => 'Removedor de Cimento Pós Obra 5L', 'categoria' => 'pisos', 'preco' => 45.00, 'descricao' => 'Remove incrustações de cimento.'],
    ['nome' => 'Limpador Perfumado Casa & Perfume 1L', 'categoria' => 'pisos', 'preco' => 8.90, 'descricao' => 'Aroma agradável por 24h.'],
    ['nome' => 'Mop Spray com Reservatório', 'categoria' => 'pisos', 'preco' => 65.00, 'descricao' => 'Praticidade na limpeza do dia a dia.'],
    ['nome' => 'Refil para Mop Spray', 'categoria' => 'pisos', 'preco' => 15.00, 'descricao' => 'Microfibra lavável.'],
    ['nome' => 'Vassoura Mágica Feiticeira', 'categoria' => 'pisos', 'preco' => 45.00, 'descricao' => 'Recolhe sujeira sem energia elétrica.'],
    ['nome' => 'Limpa Carpete e Tapete 500ml', 'categoria' => 'pisos', 'preco' => 19.90, 'descricao' => 'Remove manchas e odores.'],

    // Vidros (Glass)
    ['nome' => 'Limpa Vidros Veja Vidrex 500ml', 'categoria' => 'vidros', 'preco' => 9.90, 'descricao' => 'Vidros cristalinos sem manchas.'],
    ['nome' => 'Limpa Vidros 5L (Galão)', 'categoria' => 'vidros', 'preco' => 28.00, 'descricao' => 'Econômico para grandes áreas envidraçadas.'],
    ['nome' => 'Rodo Limpa Vidros Extensível', 'categoria' => 'vidros', 'preco' => 45.00, 'descricao' => 'Alcança janelas altas com facilidade.'],
    ['nome' => 'Refil Borracha Rodo Vidro', 'categoria' => 'vidros', 'preco' => 8.00, 'descricao' => 'Para manutenção do seu rodo.'],
    ['nome' => 'Pano Mágico para Vidros', 'categoria' => 'vidros', 'preco' => 12.00, 'descricao' => 'Não solta fiapos e não deixa marcas.'],
    ['nome' => 'Anti-embaçante para Espelhos', 'categoria' => 'vidros', 'preco' => 15.00, 'descricao' => 'Previne embaçamento no banheiro.'],
    ['nome' => 'Limpa Telas (TV/Monitor) 100ml', 'categoria' => 'vidros', 'preco' => 12.00, 'descricao' => 'Seguro para LCD e LED.'],
    ['nome' => 'Pulverizador Manual 500ml', 'categoria' => 'vidros', 'preco' => 8.00, 'descricao' => 'Ideal para aplicar produtos.'],
    ['nome' => 'Kit Limpeza de Janelas', 'categoria' => 'vidros', 'preco' => 55.00, 'descricao' => 'Rodo, lavador e pano.'],
    ['nome' => 'Limpa Box e Vidros 500ml', 'categoria' => 'vidros', 'preco' => 14.00, 'descricao' => 'Remove gordura e manchas d\'água.'],

    // Automotivo (Automotive)
    ['nome' => 'Shampoo Automotivo com Cera 500ml', 'categoria' => 'automotivo', 'preco' => 12.90, 'descricao' => 'Lava e protege a pintura.'],
    ['nome' => 'Shampoo Automotivo 5L (Galão)', 'categoria' => 'automotivo', 'preco' => 29.90, 'descricao' => 'Para lava-rápidos ou uso frequente.'],
    ['nome' => 'Pretinho para Pneus 500ml', 'categoria' => 'automotivo', 'preco' => 10.00, 'descricao' => 'Brilho molhado para os pneus.'],
    ['nome' => 'Silicone Gel para Painel 200g', 'categoria' => 'automotivo', 'preco' => 15.00, 'descricao' => 'Protege plásticos contra ressecamento.'],
    ['nome' => 'Cera em Pasta Tradicional 200g', 'categoria' => 'automotivo', 'preco' => 22.00, 'descricao' => 'Brilho intenso e proteção.'],
    ['nome' => 'Limpador de Parabrisas 100ml', 'categoria' => 'automotivo', 'preco' => 5.00, 'descricao' => 'Aditivo para o reservatório de água.'],
    ['nome' => 'Esponja para Lavagem Automotiva', 'categoria' => 'automotivo', 'preco' => 8.00, 'descricao' => 'Maciez que não risca.'],
    ['nome' => 'Toalha de Secagem Rápida', 'categoria' => 'automotivo', 'preco' => 25.00, 'descricao' => 'Alta absorção para secar o carro.'],
    ['nome' => 'Odorizante Gel Carro Novo', 'categoria' => 'automotivo', 'preco' => 9.90, 'descricao' => 'Cheirinho de carro zero.'],
    ['nome' => 'Desengraxante para Motor 500ml', 'categoria' => 'automotivo', 'preco' => 18.00, 'descricao' => 'Limpeza pesada de graxa e óleo.'],

    // Desinfetantes (Disinfectants)
    ['nome' => 'Desinfetante Eucalipto 5L (Galão)', 'categoria' => 'desinfetantes', 'preco' => 19.90, 'descricao' => 'Limpeza e perfume para grandes áreas.'],
    ['nome' => 'Desinfetante Lavanda 2L', 'categoria' => 'desinfetantes', 'preco' => 8.90, 'descricao' => 'Fragrância relaxante.'],
    ['nome' => 'Desinfetante Talco 2L', 'categoria' => 'desinfetantes', 'preco' => 8.90, 'descricao' => 'Cheirinho suave de bebê.'],
    ['nome' => 'Lysoform Bruto 1L', 'categoria' => 'desinfetantes', 'preco' => 14.00, 'descricao' => 'Bactericida potente.'],
    ['nome' => 'Desinfetante Bak 5L (Hospitalar)', 'categoria' => 'desinfetantes', 'preco' => 35.00, 'descricao' => 'Amônio quaternário.'],
    ['nome' => 'Água Sanitária Perfumada 1L', 'categoria' => 'desinfetantes', 'preco' => 6.00, 'descricao' => 'Cloro com cheirinho.'],
    ['nome' => 'Desinfetante Citronela 2L', 'categoria' => 'desinfetantes', 'preco' => 10.00, 'descricao' => 'Ajuda a espantar insetos.'],
    ['nome' => 'Creolina 500ml', 'categoria' => 'desinfetantes', 'preco' => 18.00, 'descricao' => 'Desinfetante forte para canis e ralos.'],
    ['nome' => 'Desinfetante Concentrado 140ml', 'categoria' => 'desinfetantes', 'preco' => 5.00, 'descricao' => 'Faz até 10 litros.'],
    ['nome' => 'Pastilha de Cloro Efervescente', 'categoria' => 'desinfetantes', 'preco' => 25.00, 'descricao' => 'Pote com 50 unidades.'],

    // Aromatizantes (Air Fresheners)
    ['nome' => 'Bom Ar Aerosol Lavanda 360ml', 'categoria' => 'aromatizantes', 'preco' => 14.90, 'descricao' => 'Elimina odores instantaneamente.'],
    ['nome' => 'Difusor de Varetas Alecrim 250ml', 'categoria' => 'aromatizantes', 'preco' => 25.90, 'descricao' => 'Elegância e perfume.'],
    ['nome' => 'Refil Difusor de Varetas 500ml', 'categoria' => 'aromatizantes', 'preco' => 35.00, 'descricao' => 'Reabasteça seu difusor.'],
    ['nome' => 'Spray Aromatizador de Tecidos 500ml', 'categoria' => 'aromatizantes', 'preco' => 18.00, 'descricao' => 'Para cortinas, sofás e lençóis.'],
    ['nome' => 'Glade Automático Aparelho', 'categoria' => 'aromatizantes', 'preco' => 45.00, 'descricao' => 'Spray automático temporizado.'],
    ['nome' => 'Refil Glade Automático', 'categoria' => 'aromatizantes', 'preco' => 22.00, 'descricao' => 'Várias fragrâncias.'],
    ['nome' => 'Sachê Perfumado para Gavetas', 'categoria' => 'aromatizantes', 'preco' => 8.00, 'descricao' => 'Roupas sempre cheirosas.'],
    ['nome' => 'Essência Concentrada 100ml', 'categoria' => 'aromatizantes', 'preco' => 15.00, 'descricao' => 'Para usar na limpeza.'],
    ['nome' => 'Neutralizador de Odores Pet', 'categoria' => 'aromatizantes', 'preco' => 16.00, 'descricao' => 'Remove cheiro de animais.'],
    ['nome' => 'Vela Perfumada em Copo', 'categoria' => 'aromatizantes', 'preco' => 22.00, 'descricao' => 'Decora e perfuma.'],

    // Utensílios (Tools)
    ['nome' => 'Vassoura de Pelo Sintético', 'categoria' => 'utensilios', 'preco' => 18.00, 'descricao' => 'Para pisos lisos internos.'],
    ['nome' => 'Vassoura de Piaçava Reforçada', 'categoria' => 'utensilios', 'preco' => 15.00, 'descricao' => 'Para áreas externas e calçadas.'],
    ['nome' => 'Rodo Duplo EVA 40cm', 'categoria' => 'utensilios', 'preco' => 12.50, 'descricao' => 'Seca perfeitamente.'],
    ['nome' => 'Rodo Mágico Absorvente', 'categoria' => 'utensilios', 'preco' => 35.00, 'descricao' => 'Com alavanca para torção.'],
    ['nome' => 'Pá de Lixo com Cabo Longo', 'categoria' => 'utensilios', 'preco' => 16.00, 'descricao' => 'Não precisa abaixar.'],
    ['nome' => 'Balde Plástico 15L Reforçado', 'categoria' => 'utensilios', 'preco' => 12.00, 'descricao' => 'Com alça de ferro.'],
    ['nome' => 'Saco de Lixo 100L (Pct 20un)', 'categoria' => 'utensilios', 'preco' => 22.00, 'descricao' => 'Preto, reforçado.'],
    ['nome' => 'Saco de Lixo 200L (Pct 10un)', 'categoria' => 'utensilios', 'preco' => 25.00, 'descricao' => 'Super resistente para grandes volumes.'],
    ['nome' => 'Luvas de Látex Multiuso (Par)', 'categoria' => 'utensilios', 'preco' => 5.00, 'descricao' => 'Proteção para as mãos.'],
    ['nome' => 'Escova de Tanque', 'categoria' => 'utensilios', 'preco' => 6.00, 'descricao' => 'Cerdas duras para esfregar.'],

    // Multiuso (Multi-purpose)
    ['nome' => 'Veja Multiuso Original 500ml', 'categoria' => 'multiuso', 'preco' => 5.50, 'descricao' => 'O clássico da limpeza eficiente.'],
    ['nome' => 'Cif Cremoso Original 250ml', 'categoria' => 'multiuso', 'preco' => 8.90, 'descricao' => 'Limpeza profunda sem riscar.'],
    ['nome' => 'Multiuso com Álcool 500ml', 'categoria' => 'multiuso', 'preco' => 6.50, 'descricao' => 'Secagem rápida.'],
    ['nome' => 'Lencinhos Umedecidos de Limpeza', 'categoria' => 'multiuso', 'preco' => 14.00, 'descricao' => 'Praticidade para limpar superfícies.'],
    ['nome' => 'Multiuso Galão 5L', 'categoria' => 'multiuso', 'preco' => 28.00, 'descricao' => 'Economia para limpeza geral.'],
    ['nome' => 'Tira Grude 300ml', 'categoria' => 'multiuso', 'preco' => 18.00, 'descricao' => 'Remove adesivos e chicletes.'],
    ['nome' => 'Sapólio Radium Pó 300g', 'categoria' => 'multiuso', 'preco' => 7.00, 'descricao' => 'Polidor abrasivo.'],
    ['nome' => 'Vinagre de Álcool 750ml', 'categoria' => 'multiuso', 'preco' => 3.50, 'descricao' => 'Aliado na limpeza ecológica.'],
    ['nome' => 'Bicarbonato de Sódio 1kg', 'categoria' => 'multiuso', 'preco' => 12.00, 'descricao' => 'Múltiplas utilidades na limpeza.'],
    ['nome' => 'Limpador Concentrado 500ml', 'categoria' => 'multiuso', 'preco' => 11.00, 'descricao' => 'Alto rendimento.'],

    // Lavanderia (Laundry - Aids)
    ['nome' => 'Amaciante Downy Concentrado 500ml', 'categoria' => 'lavanderia', 'preco' => 12.90, 'descricao' => 'Perfume intenso e duradouro.'],
    ['nome' => 'Amaciante Ypê Aconchego 2L', 'categoria' => 'lavanderia', 'preco' => 14.90, 'descricao' => 'Maciez clássica.'],
    ['nome' => 'Amaciante Galão 5L', 'categoria' => 'lavanderia', 'preco' => 25.00, 'descricao' => 'Economia garantida.'],
    ['nome' => 'Água de Passar Perfumada 1L', 'categoria' => 'lavanderia', 'preco' => 10.00, 'descricao' => 'Facilita o passar e perfuma.'],
    ['nome' => 'Alvejante em Pó (Tipo Vanish) 1kg', 'categoria' => 'lavanderia', 'preco' => 35.00, 'descricao' => 'Para roupas brancas e coloridas.'],
    ['nome' => 'Saco para Lavar Roupas Delicadas', 'categoria' => 'lavanderia', 'preco' => 12.00, 'descricao' => 'Protege na máquina.'],
    ['nome' => 'Prendedor de Roupas Madeira (12un)', 'categoria' => 'lavanderia', 'preco' => 4.00, 'descricao' => 'Tradicional e resistente.'],
    ['nome' => 'Corda de Varal Aço Plastificado 15m', 'categoria' => 'lavanderia', 'preco' => 8.00, 'descricao' => 'Não enferruja.'],
    ['nome' => 'Tira Ferrugem de Tecidos 50ml', 'categoria' => 'lavanderia', 'preco' => 7.00, 'descricao' => 'Salva suas roupas.'],
    ['nome' => 'Anil Líquido Colman', 'categoria' => 'lavanderia', 'preco' => 6.00, 'descricao' => 'Para deixar o branco mais branco.'],
];

try {
    $pdo->beginTransaction();
    
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE nome = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO produtos (id, nome, categoria, preco, descricao, ativo, imagem_url) VALUES (?, ?, ?, ?, ?, 1, '')");
    
    $added = 0;
    
    foreach ($products as $prod) {
        $stmtCheck->execute([$prod['nome']]);
        if ($stmtCheck->fetchColumn() == 0) {
            $id = uniqid('seed_', true);
            $stmtInsert->execute([
                $id, 
                $prod['nome'], 
                $prod['categoria'], 
                $prod['preco'], 
                $prod['descricao']
            ]);
            $added++;
        }
    }
    
    $pdo->commit();
    echo json_encode(["status" => "success", "added" => $added, "message" => "$added products added successfully."]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>