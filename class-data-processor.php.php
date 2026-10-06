<?php
/**
 * Processeur principal des données Grimmo2025
 * Orchestre l'import et l'intégration avec le système existant
 */
class GrimmoDataProcessor
{
    // Dependencies and properties are defined in the complete production class.
    /**
     * Traitement principal de l'import
     */
    public function processImport()
    {
        global $tranche_archive;
        $tranche_archive = array();
        // Augmenter les limites PHP
        $stats = array(
            'programmes_processed' => 0,
            'lots_processed'       => 0,
            'errors_count'         => 0,
            'start_time'           => $this->startTime
        );
       
        try {
            $this->logger->logImportStart();
            // Récupération des opérations (programmes)
            $operationsData = $this->apiClient->getOperations();

            if (!isset($operationsData['value']) || empty($operationsData['value'])) {
                $this->logger->warning('Aucune opération trouvée dans l\'API');
                return $stats;
            }
            $operations = $operationsData['value'];
            $total      = count($operations);
            $this->logger->info('Opérations récupérées', ['total' => $total]);

            // Traitement de chaque opération
            foreach ($operations as $index => $operation) {
                try {
                    $this->processOperation($operation, $stats);
                } catch (Exception $e) {
                    $stats['errors_count']++;
                    $this->logger->error('Erreur lors du traitement de l\'opération', [
                        'operation_id' => $operation['id'] ?? 'unknown',
                        'error'        => $e->getMessage(),
                        'file'         => $e->getFile(),
                        'line'         => $e->getLine(),
                        'trace'        => $e->getTraceAsString(),
                    ]);
                }
            }
            $this->logger->info('Toutes les opérations traitées', [
                'total'                => $total,
                'programmes_processed' => $stats['programmes_processed'],
                'lots_processed'       => $stats['lots_processed'],
                'errors_count'         => $stats['errors_count'],
            ]);
            // Archivage des éléments non présents dans le flux
            $this->flushLotJSON();
            $this->archiveObsoleteData();
            // Envoi du mail de rapport journalier
            $this->sendDailyReport($tranche_archive);
            // Calcul des statistiques finales
            $stats['duration'] = microtime(true) - $this->startTime;
            $this->logger->logImportEnd($stats);
            $this->logProgrammesSummary();
            $this->logger->info('=== FIN processImport ===', [
                'duration_sec' => round($stats['duration'], 2),
                'memory_peak'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            ]);
            return $stats;

        } catch (Exception $e) {
            $this->flushLotJSON();
            $stats['errors_count']++;
            $stats['duration'] = microtime(true) - $this->startTime;

            $this->logger->error('Erreur critique lors de l\'import', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'stats' => $stats,
            ]);

            throw $e;
        }
    }
    /**
     * Traiter une opération (programme) et ses lots
     */
    private function processOperation($operation, &$stats)
    {
        $operationId = $operation['id'];

        $this->logger->info('=== DÉBUT processOperation ===', [
            'operation_id' => $operationId,
            'libelle'      => $operation['libelle'] ?? '(inconnu)',
        ]);
        $detailProgramme  = array();
        $programme        = array();
        $programme_Statut = 'archive';
        // Récupération et traitement des lots
        try {
            $this->logger->info('Appel getLots()', ['operation_id' => $operationId]);
            $lotsData = $this->apiClient->getLots($operationId);

            $datelivraison = array();
            $tauxTVA_lots  = array();
            if (isset($lotsData['value']) && !empty($lotsData['value'])) {
                $lots = $lotsData['value'];
                $detailProgramme['lots'] = $lots;

                $this->logger->info('Lots récupérés pour l\'opération', [
                    'operation_id' => $operationId,
                    'lots_count'   => count($lots),
                ]);

                $todoallLots = true;
                foreach ($lots as $lotCheck) {
                    if (
                        !isset($lotCheck['specificite']['title']) ||
                        !in_array(trim(strtolower($lotCheck['specificite']['title'])), ['non offert', 'allotement'])
                    ) {
                        $todoallLots = false;
                        break;
                    }
                }

                if ($todoallLots) {
                    $this->logger->info('Tous les lots sont Non offert → opération ignorée', [
                        'operation_id' => $operationId,
                    ]);
                    return;
                }
                foreach ($lots as $lot) {
                    $excludeKeywords = [
                        'parking sous-sol',
                        'parking silo',
                        'cave',
                        'sub région pls',
                        'crèche',
                        'stationnement',
                        'parking'
                    ];
                    $nature    = strtolower($lot['nature'] ?? '');
                    $typeTitle = strtolower($lot['typeLot']['title'] ?? '');

                    $isExcluded = false;
                    foreach ($excludeKeywords as $keyword) {
                        if (stripos($nature, $keyword) !== false || stripos($typeTitle, $keyword) !== false) {
                            $isExcluded = true;
                            break;
                        }
                    }
                    $isSynchro = ($lot['trancheCommerciale']['synchro'] ?? false) == true;

                    if (!$isExcluded && $isSynchro) {

                        try {
                            if (empty($programme)) {

                                $this->logger->info('Premier lot éligible → appel processProgramme()', [
                                    'operation_id' => $operationId,
                                    'lot_id'       => $lot['id'] ?? '(inconnu)',
                                    'code_ci'      => $operation['codeCI'] ?? '(absent)',
                                ]);

                                // Traitement du programme
                                $programme = $this->processProgramme($operation);

                                // LOG CRITIQUE : si null on le sait maintenant
                                if (empty($programme)) {
                                    $this->logger->error('processProgramme() a retourné null/vide', [
                                        'operation_id'   => $operationId,
                                        'operation_data' => json_encode($operation),
                                    ]);
                                    return;
                                }

                                $this->logger->info('Programme créé/mis à jour', [
                                    'operation_id' => $operationId,
                                    'programme_id' => $programme['programme_id'],
                                    'tranche_id'   => $programme['tranche_id'],
                                    'code_ci'      => $programme['data']['code_ci'] ?? '(absent)',
                                ]);

                                $tranche_id = $programme['tranche_id'];
                                global $wpdb;

                                $lot_ids_query = "SELECT lot_id FROM `{$wpdb->prefix}lots_details` WHERE `program_id` = " . intval($programme['programme_id']);
                                $lot_ids = $wpdb->get_results($lot_ids_query);

                                $this->logger->info('Archivage lots existants', [
                                    'programme_id'    => $programme['programme_id'],
                                    'count'           => count($lot_ids),
                                    'wpdb_last_error' => $wpdb->last_error ?: 'aucune',
                                ]);

                                if (!empty($lot_ids)) {
                                    foreach ($lot_ids as $lot_obj) {
                                        wp_update_post([
                                            'ID'          => intval($lot_obj->lot_id),
                                            'post_status' => 'archive',
                                        ]);
                                    }
                                }
                                $forced = get_post_meta($programme['programme_id'], 'archivage_force', true);

                                $this->logger->info('Mise à jour statut tranche', [
                                    'tranche_id'      => $tranche_id,
                                    'archivage_force' => $forced ? 'oui' : 'non',
                                    'action'          => $forced ? 'ignoré' : 'publish',
                                ]);

                                if (!$forced) {
                                    wp_update_post(array(
                                        'ID'          => $tranche_id,
                                        'post_status' => 'publish',
                                    ));
                                }

                                $detailProgramme = $programme;
                                if ($programme) {
                                    $stats['programmes_processed']++;
                                }
                            }

                            $lotID = $this->processLot($lot, $programme);

                            if ($lotID != null) {
                                $mappedLot = $this->lotMapper->mapLot($lot);
                                if ($mappedLot['statut_lot_wp'] == 'publish')
                                    $programme_Statut = 'publish';

                                if (!empty($lot['informationsCalculees']['lotsSecondaires'])) {
                                    $numerosSecondaires = array_fill_keys(
                                        array_map('trim', explode(',', $lot['informationsCalculees']['lotsSecondaires'])),
                                        true
                                    );
                                    $natures = [];
                                    foreach ($lots as $l) {
                                        if (isset($numerosSecondaires[$l['numero']])) {
                                            $natures[$l['nature']] = true;
                                        }
                                    }
                                    $lotsSecondaire = implode('<br>', array_keys($natures));
                                    update_post_meta($lotID, 'prefix-lot-liste_lots_secondaires', $lotsSecondaire);
                                }

                                $tauxTVA = $lot['tauxTVA'];
                                $tauxTVA_lots[] = $tauxTVA;

                                $dateLivraisonPrevu = $lot['informationsCalculees']['dateLivraisonPrevu'];
                                if ($dateLivraisonPrevu != null) {
                                    $datelivraison[] = $dateLivraisonPrevu;
                                }
                                $stats['lots_processed']++;

                            } else {
                                $this->logger->warning('processLot() a retourné null', [
                                    'lot_id'       => $lot['id'] ?? '(inconnu)',
                                    'lot_numero'   => $lot['numero'] ?? '(inconnu)',
                                    'operation_id' => $operationId,
                                ]);
                            }

                        } catch (Exception $e) {
                            $stats['errors_count']++;
                            $this->logger->error('Erreur lors du traitement du lot', [
                                'lot_id'       => $lot['id'] ?? 'unknown',
                                'operation_id' => $operationId,
                                'error'        => $e->getMessage(),
                                'file'         => $e->getFile(),
                                'line'         => $e->getLine(),
                                'trace'        => $e->getTraceAsString(),
                            ]);
                        }
                    }
                }

                if (!empty($programme) && isset($programme['programme_id'])) {
                    
                        wp_update_post([
                            'ID'          => $programme['programme_id'],
                            'post_status' => $programme_Statut,
                        ]);

                    if (!empty($datelivraison)) {
                        $timestamps = array_map('strtotime', $datelivraison);
                        $minDate    = date('Y-m-d', min($timestamps));
                        update_post_meta($programme['programme_id'], 'prefix-date_previsionnelle_livraison', $minDate);
                        $date_previsionnelle = $this->formatDateToQuarter($minDate);
                        update_post_meta($programme['programme_id'], 'prefix-date_previsionnelle', $date_previsionnelle);

                        $this->logger->info('Date prévisionnelle mise à jour', [
                            'programme_id'        => $programme['programme_id'],
                            'min_date'            => $minDate,
                            'date_previsionnelle' => $date_previsionnelle,
                        ]);
                    }

                    if (!empty($tauxTVA_lots)) {
                        $min_tva = min($tauxTVA_lots);
                        update_post_meta($programme['programme_id'], 'prefix-taux_tva', $min_tva);

                        $this->logger->info('TVA mise à jour', [
                            'programme_id' => $programme['programme_id'],
                            'tva_min'      => $min_tva,
                        ]);
                    }
                }

            } else {
                $this->logger->info('Aucun lot trouvé pour l\'opération', [
                    'operation_id' => $operationId,
                ]);
            }
            $stats['programmes'][] = $detailProgramme;

            $this->logger->info('=== FIN processOperation ===', [
                'operation_id'     => $operationId,
                'programme_statut' => $programme_Statut,
                'elapsed_sec'      => round(microtime(true) - $this->startTime, 2),
            ]);

        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la récupération des lots', [
                'operation_id' => $operationId,
                'error'        => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    function formatDateToQuarter($dateString){}
    /**
     * Traiter un programme
     */
    private function processProgramme($operation)
    {
        
        try {
            $mappedProgramme = $this->programmeMapper->mapProgramme($operation);

            if (!$this->programmeMapper->validateMappedProgramme($mappedProgramme)) {
                $this->logger->warning('Programme invalide ignoré', [
                    'operation_id' => $operation['id'] ?? 'unknown',
                    'mapped_data'  => json_encode($mappedProgramme),
                ]);
                return null;
            }

            $result = $this->createOrUpdateProgramme($mappedProgramme);
            return $result;

        } catch (Exception $e) {
            $this->logger->error('Erreur lors du traitement du programme', [
                'operation_id' => $operation['id'] ?? 'unknown',
                'error'        => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'trace'        => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
    /**
     * Traiter un lot
     */
    private function processLot($lot, $programme)
    {
        try {
            $mappedLot = $this->lotMapper->mapLot($lot);

            if (!$this->lotMapper->validateMappedLot($mappedLot)) {
                $this->logger->warning('Lot invalide ignoré', [
                    'lot_id'      => $lot['id'] ?? 'unknown',
                    'mapped_data' => json_encode($mappedLot),
                ]);
                return false;
            }

            $lotId = $this->createOrUpdateLot($mappedLot, $programme);

            $this->processLotDocuments($lot, $lotId);
            return $lotId;

        } catch (Exception $e) {
            $this->logger->error('Erreur lors du traitement du lot', [
                'lot_id' => $lot['id'] ?? 'unknown',
                'error'  => $e->getMessage(),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
                'trace'  => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Créer ou mettre à jour un programme
     */
    private function createOrUpdateProgramme($mappedProgramme)
    {
        global $wpdb;
        $existing = $this->getProgrammeByCodeCI($mappedProgramme['code_ci']);

        if (!$existing) {
            $args = array(
                'post_type'   => 'programmes',
                'post_title'  => $mappedProgramme['nom_commercial'],
                'post_status' => 'archive',
                'post_author' => 1,
            );
            $programmeId = wp_insert_post($args);

            if (!$programmeId || is_wp_error($programmeId)) {
                $error = is_wp_error($programmeId) ? $programmeId->get_error_message() : 'ID invalide';
                $this->logger->error('wp_insert_post échoué pour programme', [
                    'code_ci' => $mappedProgramme['code_ci'],
                    'error'   => $error,
                ]);
                throw new Exception('Impossible de créer le programme : ' . $error);
            }
            $this->programmesLog[] = [
                'action'         => 'créé',
                'id'             => $programmeId,
                'nom_commercial' => $mappedProgramme['nom_commercial'],
                'code_ci'        => $mappedProgramme['code_ci'],
            ];

        } else {
            $programmeId = $existing;

            wp_update_post([
                'ID'         => $programmeId,
                'post_title' => $mappedProgramme['nom_commercial'],
            ]);
            $new_slug = sanitize_title($mappedProgramme['nom_commercial']);
            wp_update_post([
                'ID'        => $programmeId,
                'post_name' => $new_slug,
            ]);

            $this->programmesLog[] = [
                'action'         => 'modifié',
                'id'             => $programmeId,
                'nom_commercial' => $mappedProgramme['nom_commercial'],
                'code_ci'        => $mappedProgramme['code_ci'],
            ];
        }

        $this->updateProgrammeMeta($programmeId, $mappedProgramme);
        $trancheId = $this->createOrUpdateTranche($mappedProgramme, $programmeId);
        return array(
            'programme_id' => $programmeId,
            'tranche_id'   => $trancheId,
            'data'         => $mappedProgramme,
        );
    }

    /**
     * Créer ou mettre à jour une tranche
     */
    private function createOrUpdateTranche($mappedProgramme, $programmeId)
    {
        $existing = $this->getTrancheById($mappedProgramme['id_tranche_commerciale']);

        if (!$existing) {
            $args = array(
                'post_type'   => 'tranche',
                'post_title'  => $mappedProgramme['nom_tranche_commerciale'],
                'post_status' => 'publish',
                'post_author' => 1,
            );
            $trancheId = wp_insert_post($args);

            if (!$trancheId || is_wp_error($trancheId)) {
                $error = is_wp_error($trancheId) ? $trancheId->get_error_message() : 'ID invalide';
                $this->logger->error('wp_insert_post échoué pour tranche', [
                    'id_tranche' => $mappedProgramme['id_tranche_commerciale'],
                    'error'      => $error,
                ]);
                throw new Exception('Impossible de créer la tranche : ' . $error);
            }

        } else {
            $trancheId = $existing;
        }

        $this->updateTrancheMeta($trancheId, $mappedProgramme, $programmeId);

        return $trancheId;
    }

    /**
     * Créer ou mettre à jour un lot
     */
    private function createOrUpdateLot($mappedLot, $programme)
    {
        global $wpdb;
        $existing = $this->getLotById($mappedLot['id_lot']);

        if (!$existing) {
            $args = array(
                'post_type'   => 'lots',
                'post_title'  => $mappedLot['libelle_lot'],
                'post_status' => $mappedLot['statut_lot_wp'],
                'post_author' => 1,
            );
            $lotId = wp_insert_post($args);

            if (!$lotId || is_wp_error($lotId)) {
                $error = is_wp_error($lotId) ? $lotId->get_error_message() : 'ID invalide';
                $this->logger->error('wp_insert_post échoué pour lot', [
                    'id_lot' => $mappedLot['id_lot'],
                    'error'  => $error,
                ]);
                throw new Exception('Impossible de créer le lot : ' . $error);
            }
        } else {
            $lotId = $existing;

            $this->updateLotStatus($lotId, $mappedLot['statut_lot_wp']);
            wp_update_post([
                'ID'         => $lotId,
                'post_title' => $mappedLot['libelle_lot'],
            ]);
            $new_slug = sanitize_title($mappedLot['libelle_lot']);
            wp_update_post([
                'ID'        => $lotId,
                'post_name' => $new_slug,
            ]);
        }
        $this->updateLotMeta($lotId, $mappedLot, $programme);
        $this->addLotToJSON($lotId, $mappedLot);

        return $lotId;
    }
    /**
     * Traiter les documents d'un lot (plans PDF)
     */
    private function processLotDocuments($lot, $lotId)
    {
        try {
            $id_tranche   = get_post_meta($lotId, 'prefix-lot-id_tranche', true);
            $id_programme = get_post_meta($id_tranche, 'tranche-id_programme', true);
            $source       = get_post_meta($id_programme, 'prefix-source', true);

            if (!isset($lot['operation']['id']) || !isset($lot['id'])) {
                $this->logger->warning('Documents ignorés — operation.id ou lot.id manquant', [
                    'wp_lot_id' => $lotId,
                ]);
                return;
            }


            $documents = array();

            if (isset($lot['documents']) && !empty($lot['documents'])) {
                if (isset($lot['documents']['value'])) {
                    $documents = $lot['documents'];

                } elseif (is_array($lot['documents']) && isset($lot['documents'][0])) {
                    $documents = array('value' => $lot['documents']);
                } else {
                    $this->logger->debug('Format documents inconnu dans le cache, appel API requis');
                    $documents = $this->apiClient->getLotDocuments($lot['operation']['id'], $lot['id']);
                }
            } else {

                $documents = $this->apiClient->getLotDocuments($lot['operation']['id'], $lot['id']);
            }

            if (isset($documents['value']) && !empty($documents['value'])) {
                foreach ($documents['value'] as $document) {
                    $this->downloadAndAttachDocument($document, $lotId);
                }
            } else {
                $this->logger->debug('Aucun document trouvé pour le lot', [
                    'lot_id' => $lot['id'],
                ]);
            }

        } catch (Exception $e) {
            $this->logger->warning('Erreur lors du traitement des documents', [
                'lot_id'    => $lot['id'] ?? 'unknown',
                'wp_lot_id' => $lotId,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }
    private function getProgrammeByCodeCI($codeCI){}
    private function getTrancheById($trancheId){}
    private function getLotById($lotId){}
    private function updateProgrammeMeta($programmeId, $data)
    {
        $metaFields = array(
            'prefix-source'                        => $data['source'],
            'prefix-code_ci'                       => $data['code_ci'],
            'prefix-cle_programme'                 => $data['cle_programme'],
            'prefix-id_operation'                  => $data['id_operation'],
            'prefix-date_previsionnelle'           => $data['date_previsionnelle_livraison'],
            'prefix-date_previsionnelle_livraison' => $data['date_previsionnelle_livraison'],
            'date_import'                          => $data['date_import'],
        );

        foreach ($metaFields as $key => $value) {
            if ($value !== null && $value !== '') {
                update_post_meta($programmeId, $key, $value);
            }
        }
    }

    private function updateTrancheMeta($trancheId, $data, $programmeId)
    {
        $metaFields = array(
            'tranche-id_tranche_commerciale'     => $data['id_tranche_commerciale'],
            'tranche-nom_tranche_commerciale'    => $data['nom_tranche_commerciale'],
            'tranche-nature_tranche_commerciale' => $data['nature_tranche_commerciale'],
            'tranche-n_tranche_commerciale'      => $data['n_tranche_commerciale'],
            'tranche-tranche_commerciale_active' => $data['tranche_commerciale_active'],
            'tranche-cle_tranche_commerciale'    => $data['cle_tranche_commerciale'],
            'tranche-id_programme'               => $programmeId,
            'tranche-id_tranche_travaux'         => $data['id_tranche_travaux'],
        );
        foreach ($metaFields as $key => $value) {
            if ($value !== null && $value !== '') {
                update_post_meta($trancheId, $key, $value);
            }
        }
    }

    private function updateLotMeta($lotId, $data, $programme)
    {
        $metaFields = array(
            'prefix-lot-id_lot'          => $data['id_lot'],
            /*
            .
            .
            .
            */
            'prefix-lot-loyer_ttc'       => $data['loyer_mensuel_ttc'],
            'prefix-lot-montant_loyer'   => $data['loyer_mensuel_ttc'],
            'prefix-lot-date_import'     => $data['date_import'],
        );
        if (isset($data['tranche_synchro'])) {
            update_post_meta($lotId, 'prefix-lot-tranche_synchro', $data['tranche_synchro']);
        }
        foreach ($metaFields as $key => $value) {
            if ($value !== null && $value !== '') {
                update_post_meta($lotId, $key, $value);
            }
        }
    }

    private function updateLotStatus($lotId, $status)
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, array('post_status' => $status), array('ID' => $lotId));
        clean_post_cache($lotId);
        $post              = get_post($lotId);
        $old_status        = $post->post_status;
        $post->post_status = $status;
        wp_transition_post_status($status, $old_status, $post);
    }

    private function addLotToJSON($lotId, $data)
    {
        $newEntry = array(
            'id_post_lot' => $lotId,
            'id_lot'      => $data['id_lot'],
            'libelle_lot' => $data['libelle_lot'],
            'status'      => $data['statut_lot_wp'],
            'current_time'=> $data['date_import'],
            'plan_source' => $data['nom_plan'],
        );
        $this->jsonLotEntries[] = $newEntry;
    }
    private function flushLotJSON(){}
    private function downloadAndAttachDocument($document, $lotId){}
    private function sendDailyReport($trancheArchive){}
    private function logProgrammesSummary(){}
    private function archiveObsoleteData(){}
}