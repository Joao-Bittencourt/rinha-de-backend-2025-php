#!/bin/bash

# Define o nome do arquivo de saída
OUTPUT_FILE="docker_stats_$(date +%Y%m%d_%H%M%S).txt"

echo "Coletando dados do 'docker stats' a cada 5 segundos. Pressione Ctrl+C para parar."
echo "Os dados serão salvos em: $OUTPUT_FILE"
echo "" >> "$OUTPUT_FILE" # Adiciona uma linha vazia para iniciar o arquivo

# Loop infinito para coletar os dados
while true; do
    # Adiciona um cabeçalho de tempo para cada bloco de dados
    echo "--- $(date '+%Y-%m-%d %H:%M:%S') ---" | tee -a "$OUTPUT_FILE"
    docker stats --no-stream | tee -a "$OUTPUT_FILE"
    echo "" | tee -a "$OUTPUT_FILE" # Adiciona uma linha vazia para separar os blocos
    sleep 5
done    