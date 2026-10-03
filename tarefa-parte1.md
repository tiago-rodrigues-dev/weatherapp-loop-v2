# Projeto - API De tempo

## Objetivo

Criar uma api que vai ser responsável por salvar no banco de dados as informacoes meteorologicas de uma cidade que o usuario enviar usando a api externa do OpenWeatherMap. Também teremos uma rota para consultar o histórico dessa pesquisa. Antes dessas duas rotas, precisaremos de uma rota onde o usuário irá digitar parte do nome da cidade, e vamos usar uma tabela com os nomes de todos os municipios do País.

## Requisitos técnicos

Vamos utilizar o json chamado <mark> cidades.json</mark> Criar um seeder para salvar a sigla e a cidade, e vamos criar uma rota para consumir esses dados para sugerir no auto-complete da interface, posteriormente.

Na migration da tabela consulta_clima, vamos precisar salvar esses dados: 

cidade, data e hora da consulta, temperatura, sensação térmica,
umidade e descrição do tempo. 

A rota de POST irá receber o nome da cidade via query params e irá fazer o processo de Controller > Service > Provider API OpenWeather > Service recebe os dados > Repository para salvar no banco de dados.

A rota de cidades também precisa ter o padrão de Service e Repository.

Todas as falhas que acontecerem na API devem ser tratadas e retornadas de forma objetiva e clara para o usuário.

Vamos usar cache para trazer os dados do histórico.

Não pode ser aceito registros duplicados com redundancia, como por exemplo: Fiz variás requisições de tempo para a mesma cidade, onde a temperatura permaneceu a mesma, não faz sentido salvar este dado no banco, nesse caso podemos atualizar o registro que já está no banco alterando a data e hora de consulta para esta ultima.

Banco de dados: sqlite.

Devemos criar testes de integração validando o fluxo da API e testes unitários validando a lógica do service, principalmente a parte de quando salvar ou quando editar um registro pré existente citado acima, e o cache da rota de histórico.



# CLAUDE

Você está sendo executada no modo /plan, então todas as ambiguidades que você ficar com dúvida, não hesite em perguntar.

Apos finalizar o plano, você irá criar um .md chamado <mark> implementacao.md</mark> onde vai ter toda a estrutura de código e uma breve descricão em cima de cada arquivo/código. A ideia é eu mesmo implementar manualmente esses códigos, então a sua responsabilidade é apenas colocar os trechos de código nesse .md.


