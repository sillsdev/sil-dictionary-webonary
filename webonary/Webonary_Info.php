<?php


class Webonary_Info
{
	/** @var IIndexedCounts */
	private static $post_counts;

	/** @var int */
	private static $category_id;

	/**
	 * @return int
	 */
	public static function category_id()
	{
		global $wpdb;

		if (!is_null(self::$category_id))
			return self::$category_id;

		/** @noinspection SqlResolve */
		self::$category_id = (int)$wpdb->get_var("SELECT term_id FROM {$wpdb->terms} WHERE slug = 'webonary'");

		if (self::$category_id == 0) {

			/** @noinspection SqlResolve */
			$sql = "INSERT INTO {$wpdb->terms} (`name`, slug, term_group) VALUES ('Webonary', 'webonary', 0)";
			$wpdb->query($sql);

			self::$category_id = $wpdb->insert_id;
		}


		return self::$category_id;
	}

	public static function getCountIndexed()
	{
		$cat_id = self::category_id();
		$counts = self::postCountByImportStatus($cat_id);
		return (int)(empty($counts->indexed_count) ? 0 : $counts->indexed_count);
	}

	public static function getCountImported()
	{
		$cat_id = self::category_id();
		$counts = self::postCountByImportStatus($cat_id);
		return (int)(empty($counts->unindexed_count) ? 0 : $counts->unindexed_count);
	}

	/**
	 * @return string
	 */
	public static function import_status()
	{
		global $wpdb;

		$cat_id = self::category_id();
		$counts = self::postCountByImportStatus($cat_id);

		if($counts->total_count == 0)
			return 'No entries have been imported yet. <a href="' . $_SERVER['REQUEST_URI']  . '">refresh page</a>';

		$import_status = get_option('importStatus');

		if(empty($import_status))
			return 'The import status will display here.<br>';

		$status = '';

		if(get_option('useSemDomainNumbers') == 0)
		{
			/** @noinspection SqlResolve */
			$sql = "SELECT COUNT(taxonomy) AS sdCount FROM {$wpdb->prefix}term_taxonomy WHERE taxonomy = 'sil_semantic_domains'";

			$sdCount = $wpdb->get_var($sql);

			if($sdCount > 0)
			{
				$status .= '<br>';
				$status .= '<span style="color:red;">It appears you imported semantic domains without the domain numbers. Please go to Tools -> Configure -> Dictionary.. in FLEx and check "Abbreviation" under Senses/Semantic Domains.</span><br>';
				$status .= 'Tip: You can hide the domain numbers from displaying, <a href=" https://www.webonary.org/help/tips-tricks/" target=_"blank">see here</a>.';
				$status .= '<hr>';
			}
		}

		$arrReversalsImported = self::reversalPosts();
		$arrIndexed = self::number_of_entries();
		$countIndexed = self::getCountIndexed();
		$countImported = self::getCountImported();

		if($import_status == 'importFinished')
		{
			if(!empty($posts) && !empty($posts->post_date))
			{
				$status .= 'Last import of configured xhtml was at ' . $posts->post_date . ' (server time).<br>';
				$status .= 'Download data sent from FLEx: ';

				$sub_domain = explode('.', $_SERVER['HTTP_HOST'])[0];
				$archiveFile = $sub_domain . '.zip';

				if(file_exists(WP_CONTENT_DIR . '/archives/' . $archiveFile))
					$status .= '<a href="/wp-content/archives/' . $archiveFile . '">' . $archiveFile . '</a>';
				else
					$status .= 'no longer available';

				$status .= '<br>';
			}
		}
		else
		{
			$status .= 'Importing... <a href="' . $_SERVER['REQUEST_URI']  . '">refresh page</a><br>';
			$status .= ' You will receive an email when the import has completed. You don\'t need to stay online.';
			$status .= '<br>';
		}

		if($import_status == 'indexing')
		{
			$totalImportedPosts = count(self::posts());

			$status .= 'Indexing <span id="sil-count-indexed" class="sil-bold">' . $countIndexed . '</span> of <span class="sil-bold">' . $totalImportedPosts . '</span> entries' . PHP_EOL;
			$status .= '<br>If you believe indexing has timed out, click here: <input type="submit" name="btnReindex" id="btnReindex" value="Index Search Strings" formaction="admin.php?import=pathway-xhtml&step=2">';

			return $status;
		}

		if($import_status == 'configured')
		{
			$status .= $countImported . ' entries imported (not yet indexed)';

			if($counts->time_diff > 5)
			{
				$status .= '<br>It appears the import has timed out, click here: <input type="submit" name="btnRestartImport" value="Restart Import" formaction="admin.php?import=pathway-xhtml&step=2">';
			}
			return $status;
		}

		if($import_status == 'reversal')
		{
			$status .= '<strong>Importing reversals. So far imported: ' . count($arrReversalsImported) . ' entries.</strong>';

			$status .= '<br>If you believe the import has timed out, click here: <input type="submit" name="btnRestartReversalImport" value="Restart Reversal Import" formaction="admin.php?import=pathway-xhtml&step=2">';
			return $status;
		}

		if($import_status == 'importFinished')
		{
			$status .= '<br>';
			$status .= '<div style="float: left;">';
			$status .= '<strong>Number of indexed entries (by language code):</strong><br>';
			$status .= '</div>';
			$status .= '<div style="min-width:50px; float: left; margin-left: 5px;">';

			$status .= self::reversalsMissing($arrIndexed);

			$status .= '</div>';
			$status .= '<br style="clear:both;">';
		}

		return $status;
	}

	public static function number_of_entries()
	{
		global $wpdb;

		//gets the language codes for all entries plus number of indexed entries
		//(number of reversal entries is not exact, which is why we get reversal entries separately)
		$table_name = Webonary_Configuration::$search_table_name;
		/** @noinspection SqlResolve */
		$sql = <<<SQL
SELECT language_code, COUNT(post_id) AS totalIndexed
FROM {$table_name}
WHERE relevance = 100
GROUP BY language_code
SQL;

		$arrIndexed = $wpdb->get_results($sql);

		$table_name = Webonary_Configuration::$reversal_table_name;
		/** @noinspection SqlResolve */
		$sql = <<<SQL
SELECT r.language_code, t.name as language_name, COUNT(r.language_code) AS totalIndexed
FROM {$table_name} AS r
  RIGHT JOIN {$wpdb->terms} AS t ON t.slug = r.language_code
GROUP BY r.language_code
ORDER BY t.name, r.language_code
SQL;

		$arrReversals = $wpdb->get_results($sql);

		$s = 0;
		foreach($arrIndexed as $key => $indexed)
		{
			/** @noinspection SqlResolve */
			$sqlLangName = <<<SQL
SELECT `name`
FROM {$wpdb->terms}
WHERE slug = '{$indexed->language_code}'
ORDER BY name, slug
LIMIT 1
SQL;

			$language_name = $wpdb->get_var($sqlLangName);

			$arrIndexed[$key]->language_name = $language_name;

			if($arrReversals[$s]->language_code == $indexed->language_code)
			{
				$arrIndexed[$key]->totalIndexed = $arrReversals[$s]->totalIndexed;
				$s++;
			}
		}

		//legacy code, to count approximate number of reversals before we imported reversal entries into
		//the reversal table
		if(count($arrReversals) == 0 && count($arrIndexed) > 0)
		{
			$table_name = Webonary_Configuration::$search_table_name;
			$char_set = MYSQL_CHARSET;
			$count_posts = count(self::posts(''));
			foreach($arrIndexed as $key => $indexed)
			{
				/** @noinspection SqlResolve */
				$sql = <<<SQL
SELECT search_strings
FROM {$table_name} 
WHERE language_code = '{$indexed->language_code}'
AND relevance >= 95
GROUP BY search_strings COLLATE {$char_set}_BIN
SQL;

				$arrIndexGrouped = $wpdb->get_results($sql);

				if($count_posts != $indexed->totalIndexed && ($count_posts + 1) != $indexed->totalIndexed)
					$arrIndexed[$key]->totalIndexed = count($arrIndexGrouped);
			}
		}

		return $arrIndexed;
	}

	public static function posts($index = ""){
		global $wpdb;

		// @todo: If $headword_text has a double quote in it, this
		// will probably fail.
		$sql = "SELECT ID, post_title, post_content, post_parent, menu_order " .
			" FROM $wpdb->posts " .
			" INNER JOIN " . $wpdb->prefix . "term_relationships ON object_id = ID " .
			" WHERE " . $wpdb->prefix . "term_relationships.term_taxonomy_id = " . self::category_id();
		//using pinged field for not yet indexed
		$sql .= " AND post_status = 'publish'";
		if(strlen($index) > 0 && $index != "-")
		{
			$sql .= " AND pinged = '" . $index . "'";
		}
		if($index == "-")
		{
			$sql .= " AND pinged = ''";
		}
		$sql .= " ORDER BY menu_order ASC";

		return $wpdb->get_results($sql);
	}

	/**
	 * @param $cat_id
	 * @return IIndexedCounts
	 */
	public static function postCountByImportStatus($cat_id)
	{
		global $wpdb;

		if (!is_null(self::$post_counts))
			return self::$post_counts;

		/** @noinspection SqlResolve */
		$sql = <<<SQL
SELECT SUM(IF(pinged IN ('indexed', 'linksconverted'), 1, 0)) AS indexed_count,
       MAX(IF(pinged IN ('indexed', 'linksconverted'), post_date, NULL)) AS indexed_date,
       SUM(IF(pinged IN ('indexed', 'linksconverted'), 0, 1)) AS unindexed_count,
       MAX(IF(pinged IN ('indexed', 'linksconverted'), NULL, post_date)) AS unindexed_date,
       COUNT(*) AS total_count,
       TIMESTAMPDIFF(SECOND, MAX(post_date),NOW()) AS time_diff
FROM {$wpdb->prefix}posts
WHERE post_type IN ('post', 'revision')
  AND ID IN (
    SELECT object_id
    FROM {$wpdb->prefix}term_relationships
    WHERE term_taxonomy_id = {$cat_id}
  );
SQL;

		self::$post_counts = $wpdb->get_row($sql);

		return self::$post_counts;
	}

	public static function reversalsMissing($arrIndexed)
	{
		global $wpdb;

		$status = "";
		foreach($arrIndexed as $indexed)
		{
			$status .= '<div style="clear:both;"><div style="text-align:right;float:left;white-space:nowrap">' . $indexed->language_code . ':</div><div style="float:left;">&nbsp;'. $indexed->totalIndexed;

			$table_name = Webonary_Configuration::$search_table_name;
			/** @noinspection SqlResolve */
			$sql = <<<SQL
SELECT COUNT(language_code) AS missing
FROM {$table_name}
WHERE post_id = 0 AND language_code = '{$indexed->language_code}'
SQL;

			$missingReversals = $wpdb->get_var($sql);

			if($missingReversals > 0)
				$status .= ' <a href="edit.php?page=sil-dictionary-webonary/include/configuration.php&reportMissingSenses=1&languageCode=' . $indexed->language_code . '&language=' . $indexed->language_name . '" style="color:red;">missing senses for ' . $missingReversals . ' entries</a>';

			$status .= '</div></div>';
		}
		return $status;
	}

	public static function reversalPosts()
	{
		global $wpdb;

		$sql = 'SELECT * FROM ' . Webonary_Configuration::$reversal_table_name;

		return $wpdb->get_results($sql);
	}

}