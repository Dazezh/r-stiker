<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Service;

use OCA\RStiker\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Sticker storage inside the app data folder (no database, no user files):
 *
 *   r-stiker/packs/packs.json                    index of the packs and their metadata
 *   r-stiker/packs/<pack name>/<sticker>.webp    sticker file
 *
 * A pack is a folder, a sticker is a file. Physical paths never leave the app.
 */
class StorageService {
	public const PACKS_FOLDER = 'packs';
	public const INDEX_FILE = 'packs.json';

	/** Allowed sticker file extensions. */
	public const EXTENSIONS = ['png', 'gif', 'jpg', 'jpeg', 'webp'];

	/** Letters, numbers, spaces and a few safe separators. No slashes, no dots at the end. */
	private const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} \-_.]*$/u';

	private ?IAppData $appData = null;

	public function __construct(
		private readonly IAppDataFactory $appDataFactory,
	) {
	}

	private function getAppData(): IAppData {
		if ($this->appData === null) {
			$this->appData = $this->appDataFactory->get(Application::APP_ID);
		}

		return $this->appData;
	}

	public function getPacksFolder(): ISimpleFolder {
		$appData = $this->getAppData();
		try {
			return $appData->getFolder(self::PACKS_FOLDER);
		} catch (NotFoundException) {
			try {
				return $appData->newFolder(self::PACKS_FOLDER);
			} catch (NotPermittedException|NotFoundException $e) {
				throw new \RuntimeException('Sticker storage is not writable', 0, $e);
			}
		}
	}

	/**
	 * The app data API can list the folders of the app data root and the files of
	 * a folder, but not the sub folders of a folder. Therefore the sticker packs
	 * kept in "packs/" are tracked in a small index file next to them.
	 *
	 * @return array<string, array{displayName: string, description: string, titles: array<string, string>}>
	 */
	public function readIndex(): array {
		try {
			$raw = $this->getPacksFolder()->getFile(self::INDEX_FILE)->getContent();
		} catch (\Exception) {
			return [];
		}

		$data = json_decode($raw, true);
		if (!is_array($data) || !is_array($data['packs'] ?? null)) {
			return [];
		}

		$index = [];
		foreach ($data['packs'] as $packName => $metadata) {
			if (!is_string($packName) || !self::isValidPackName($packName) || !is_array($metadata)) {
				continue;
			}

			$titles = [];
			if (is_array($metadata['titles'] ?? null)) {
				foreach ($metadata['titles'] as $fileName => $title) {
					if (is_string($fileName) && is_string($title)) {
						$titles[$fileName] = $title;
					}
				}
			}

			$sizes = [];
			if (is_array($metadata['sizes'] ?? null)) {
				foreach ($metadata['sizes'] as $fileName => $size) {
					if (is_string($fileName) && is_array($size) && isset($size[0], $size[1])) {
						$width = (int)$size[0];
						$height = (int)$size[1];
						if ($width > 0 && $height > 0) {
							$sizes[$fileName] = [$width, $height];
						}
					}
				}
			}

			$displayName = is_string($metadata['displayName'] ?? null) ? trim($metadata['displayName']) : '';
			$index[$packName] = [
				'displayName' => $displayName !== '' ? $displayName : $packName,
				'description' => is_string($metadata['description'] ?? null) ? $metadata['description'] : '',
				'titles' => $titles,
				'sizes' => $sizes,
			];
		}

		return $index;
	}

	/**
	 * @param array<string, array{displayName: string, description: string, titles: array<string, string>}> $index
	 */
	public function writeIndex(array $index): void {
		ksort($index);
		$payload = json_encode(
			['packs' => $index],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ($payload === false) {
			throw new \RuntimeException('Could not encode the sticker index');
		}

		$folder = $this->getPacksFolder();
		try {
			$folder->getFile(self::INDEX_FILE)->putContent($payload);
		} catch (NotFoundException) {
			$folder->newFile(self::INDEX_FILE, $payload);
		} catch (\Exception $e) {
			throw new \RuntimeException('Could not store the sticker index', 0, $e);
		}
	}

	/**
	 * @return list<string> names of all existing packs
	 */
	public function getPackNames(): array {
		$names = [];
		foreach (array_keys($this->readIndex()) as $packName) {
			if ($this->getPackFolder($packName) !== null) {
				$names[] = $packName;
			}
		}

		return $names;
	}

	public function getPackFolder(string $packName): ?ISimpleFolder {
		if (!self::isValidPackName($packName)) {
			return null;
		}

		try {
			return $this->getPacksFolder()->getFolder($packName);
		} catch (NotFoundException) {
			return null;
		}
	}

	/**
	 * @return array{displayName: string, description: string, titles: array<string, string>}|null
	 */
	public function getPackMetadata(string $packName): ?array {
		return $this->readIndex()[$packName] ?? null;
	}

	/**
	 * @param array{displayName: string, description: string, titles: array<string, string>} $metadata
	 */
	public function setPackMetadata(string $packName, array $metadata): void {
		$index = $this->readIndex();
		$index[$packName] = $metadata;
		$this->writeIndex($index);
	}

	public function removePackMetadata(string $packName): void {
		$index = $this->readIndex();
		if (isset($index[$packName])) {
			unset($index[$packName]);
			$this->writeIndex($index);
		}
	}

	public function renamePackMetadata(string $from, string $to): void {
		$index = $this->readIndex();
		if (isset($index[$from])) {
			$index[$to] = $index[$from];
			unset($index[$from]);
			$this->writeIndex($index);
		}
	}

	public function hasPackMetadata(string $packName): bool {
		return array_key_exists($packName, $this->readIndex());
	}

	/**
	 * @return array{displayName: string, description: string, titles: array<string, string>}
	 */
	public function readMetadata(string $packName): array {
		return $this->getPackMetadata($packName)
			?? ['displayName' => $packName, 'description' => '', 'titles' => [], 'sizes' => []];
	}

	/**
	 * @param array{displayName: string, description: string, titles: array<string, string>} $metadata
	 */
	public function writeMetadata(string $packName, array $metadata): void {
		$this->setPackMetadata($packName, $metadata);
	}

	public function createPackFolder(string $packName): ISimpleFolder {
		if (!self::isValidPackName($packName)) {
			throw new \InvalidArgumentException('Invalid pack name');
		}
		if ($this->getPackFolder($packName) !== null) {
			throw new \InvalidArgumentException('Pack already exists');
		}

		try {
			return $this->getPacksFolder()->newFolder($packName);
		} catch (NotPermittedException|NotFoundException $e) {
			throw new \RuntimeException('Could not create the pack folder', 0, $e);
		}
	}

	/**
	 * App data has no rename operation, so the folder is copied and removed.
	 */
	public function renamePackFolder(string $from, string $to): void {
		$source = $this->getPackFolder($from);
		if ($source === null) {
			throw new \InvalidArgumentException('Pack not found');
		}

		$target = $this->createPackFolder($to);
		foreach ($this->getFiles($source) as $file) {
			$target->newFile($file->getName(), $file->getContent());
		}
		$source->delete();
	}

	public function deletePackFolder(string $packName): void {
		$folder = $this->getPackFolder($packName);
		if ($folder === null) {
			throw new \InvalidArgumentException('Pack not found');
		}

		$folder->delete();
	}

	/**
	 * @return list<ISimpleFile> sticker files of a pack, naturally sorted
	 */
	public function getStickerFiles(string $packName): array {
		$folder = $this->getPackFolder($packName);
		if ($folder === null) {
			return [];
		}

		$files = [];
		foreach ($this->getFiles($folder) as $file) {
			if (self::isValidFileName($file->getName())) {
				$files[] = $file;
			}
		}

		usort($files, static fn (ISimpleFile $a, ISimpleFile $b): int => strnatcasecmp($a->getName(), $b->getName()));

		return $files;
	}

	public function getStickerFile(string $packName, string $fileName): ?ISimpleFile {
		if (!self::isValidFileName($fileName)) {
			return null;
		}

		foreach ($this->getStickerFiles($packName) as $file) {
			if ($file->getName() === $fileName) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * @return list<ISimpleFile>
	 */
	private function getFiles(ISimpleFolder $folder): array {
		$files = [];
		try {
			foreach ($folder->getDirectoryListing() as $entry) {
				if ($entry instanceof ISimpleFile) {
					$files[] = $entry;
				}
			}
		} catch (NotFoundException) {
			return [];
		}

		return $files;
	}

	public static function isValidPackName(string $name): bool {
		if ($name === '' || mb_strlen($name) > 64 || str_contains($name, '..')) {
			return false;
		}
		if (str_starts_with($name, ' ') || str_ends_with($name, ' ') || str_ends_with($name, '.')) {
			return false;
		}

		return preg_match(self::NAME_PATTERN, $name) === 1;
	}

	public static function isValidFileName(string $name): bool {
		if ($name === '' || mb_strlen($name) > 128 || str_contains($name, '..')) {
			return false;
		}
		if (preg_match(self::NAME_PATTERN, $name) !== 1) {
			return false;
		}

		return in_array(self::getExtension($name), self::EXTENSIONS, true);
	}

	public static function getExtension(string $fileName): string {
		return strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
	}
}
