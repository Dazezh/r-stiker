<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Service;

use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;

/**
 * Packs and stickers of the app. Sticker ids are url-safe base64 of
 * "pack name:file name", so they stay stable, opaque and safe for URLs.
 */
class StickerService {
	/** Mime types accepted for stickers, in the order announced in capabilities. */
	public const SUPPORTED_MIME_TYPES = ['image/png', 'image/gif', 'image/jpeg', 'image/webp'];

	/** Upload limit for the administration UI (5 MiB). */
	public const MAX_FILE_SIZE = 5 * 1024 * 1024;

	/** Hard limit which also applies to imported files (25 MiB). */
	public const ABSOLUTE_MAX_FILE_SIZE = 25 * 1024 * 1024;

	private const MIME_EXTENSIONS = [
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	];

	public function __construct(
		private readonly StorageService $storage,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return list<array{name: string, displayName: string, description: string, stickerCount: int}>
	 */
	public function getPacks(): array {
		$packs = [];
		foreach ($this->storage->getPackNames() as $packName) {
			$pack = $this->buildPackData($packName);
			if ($pack !== null) {
				$packs[] = $pack;
			}
		}

		usort($packs, static fn (array $a, array $b): int => strnatcasecmp($a['displayName'], $b['displayName']));

		return $packs;
	}

	/**
	 * @return array{name: string, displayName: string, description: string, stickerCount: int}|null
	 */
	public function getPack(string $packName): ?array {
		return $this->buildPackData($packName);
	}

	/**
	 * @return array{name: string, displayName: string, description: string, stickerCount: int}
	 */
	public function createPack(string $name, ?string $displayName = null, ?string $description = null): array {
		$name = trim($name);
		$this->storage->createPackFolder($name);
		$this->storage->writeMetadata($name, [
			'displayName' => $displayName !== null && trim($displayName) !== '' ? trim($displayName) : $name,
			'description' => trim((string)$description),
			'titles' => [],
		]);

		$pack = $this->buildPackData($name);
		if ($pack === null) {
			throw new \RuntimeException('Could not create the sticker pack');
		}

		return $pack;
	}

	/**
	 * @return array{name: string, displayName: string, description: string, stickerCount: int}
	 */
	public function updatePack(string $packName, ?string $name = null, ?string $displayName = null, ?string $description = null): array {
		if ($this->storage->getPackFolder($packName) === null) {
			throw new \InvalidArgumentException('Pack not found');
		}

		$newName = trim($name ?? $packName);
		if ($newName !== $packName) {
			if (!StorageService::isValidPackName($newName)) {
				throw new \InvalidArgumentException('Invalid pack name');
			}
			if ($this->storage->getPackFolder($newName) !== null) {
				throw new \InvalidArgumentException('A pack with this name already exists');
			}
			$this->storage->renamePackFolder($packName, $newName);
			$this->storage->renamePackMetadata($packName, $newName);
		}

		$metadata = $this->storage->readMetadata($newName);
		if ($displayName !== null) {
			$displayName = trim($displayName);
			$metadata['displayName'] = $displayName === '' ? $newName : $displayName;
		}
		if ($description !== null) {
			$metadata['description'] = trim($description);
		}
		$this->storage->writeMetadata($newName, $metadata);

		$pack = $this->buildPackData($newName);
		if ($pack === null) {
			throw new \RuntimeException('Could not update the sticker pack');
		}

		return $pack;
	}

	public function deletePack(string $packName): void {
		$this->storage->deletePackFolder($packName);
		$this->storage->removePackMetadata($packName);
	}

	/**
	 * @return array{entries: list<array{id: string, name: string, title: string, thumbnailUrl: string, resourceUrl: string}>, cursor: int|null, total: int}
	 */
	public function getStickers(string $packName, int $cursor = 0, int $limit = 60): array {
		if ($this->storage->getPackFolder($packName) === null) {
			return ['entries' => [], 'cursor' => null, 'total' => 0];
		}

		$cursor = max(0, $cursor);
		$limit = max(1, min($limit, 200));

		$files = $this->storage->getStickerFiles($packName);
		$metadata = $this->storage->readMetadata($packName);

		$entries = [];
		foreach (array_slice($files, $cursor, $limit) as $file) {
			$entries[] = $this->buildStickerEntry($packName, $file->getName(), $metadata['titles']);
		}

		$next = $cursor + count($entries);

		return [
			'entries' => $entries,
			'cursor' => $next < count($files) ? $next : null,
			'total' => count($files),
		];
	}

	/**
	 * Sticker metadata including the absolute sticker URL.
	 *
	 * @return array{id: string, name: string, title: string, thumbnailUrl: string, resourceUrl: string, pack: string, packDisplayName: string, mime: string}|null
	 */
	public function getSticker(string $stickerId): ?array {
		$decoded = $this->decodeStickerId($stickerId);
		if ($decoded === null) {
			return null;
		}

		[$packName, $fileName] = $decoded;
		if ($this->storage->getStickerFile($packName, $fileName) === null) {
			return null;
		}

		$metadata = $this->storage->readMetadata($packName);

		return $this->buildStickerEntry($packName, $fileName, $metadata['titles'])
			+ [
				'pack' => $packName,
				'packDisplayName' => $metadata['displayName'],
				'mime' => $this->getMimeType($fileName),
			];
	}

	/**
	 * @return array{mime: string, content: string, etag: string}|null
	 */
	public function getStickerContent(string $stickerId): ?array {
		$decoded = $this->decodeStickerId($stickerId);
		if ($decoded === null) {
			return null;
		}

		[$packName, $fileName] = $decoded;
		$file = $this->storage->getStickerFile($packName, $fileName);
		if ($file === null) {
			return null;
		}

		try {
			$content = $file->getContent();
		} catch (\Exception) {
			return null;
		}

		return [
			'mime' => $this->getMimeType($fileName),
			'content' => $content,
			'etag' => $file->getETag(),
		];
	}

	public function hasSticker(string $packName, string $fileName): bool {
		return $this->storage->getStickerFile($packName, $fileName) !== null;
	}

	/**
	 * Validates and stores a sticker file. The mime type is detected from the
	 * file content, never from the file name or the browser provided type.
	 *
	 * @return array{id: string, name: string, title: string, thumbnailUrl: string, resourceUrl: string}
	 */
	public function addSticker(string $packName, string $sourcePath, string $originalName, ?string $title = null): array {
		if ($this->storage->getPackFolder($packName) === null) {
			throw new \InvalidArgumentException('Pack not found');
		}

		$size = is_file($sourcePath) ? (int)filesize($sourcePath) : 0;
		if ($size <= 0) {
			throw new \InvalidArgumentException('Empty file');
		}
		if ($size > self::ABSOLUTE_MAX_FILE_SIZE) {
			throw new \InvalidArgumentException('File is too large');
		}

		$mimeType = $this->detectMimeType($sourcePath);
		if ($mimeType === null) {
			throw new \InvalidArgumentException('Unsupported file type, only PNG, GIF, JPEG and WEBP stickers are allowed');
		}
		$extension = self::MIME_EXTENSIONS[$mimeType];

		$content = file_get_contents($sourcePath);
		if ($content === false) {
			throw new \RuntimeException('Could not read the sticker file');
		}

		$folder = $this->storage->getPackFolder($packName);
		if ($folder === null) {
			throw new \InvalidArgumentException('Pack not found');
		}

		$fileName = $this->buildUniqueFileName($packName, $this->sanitizeFileName($originalName), $extension);
		try {
			$folder->newFile($fileName, $content);
		} catch (NotPermittedException $e) {
			throw new \RuntimeException('Could not store the sticker', 0, $e);
		}

		$metadata = $this->storage->readMetadata($packName);
		$title = trim((string)$title);
		$metadata['titles'][$fileName] = $title !== '' ? $title : pathinfo($fileName, PATHINFO_FILENAME);
		$this->storage->writeMetadata($packName, $metadata);

		return $this->buildStickerEntry($packName, $fileName, $metadata['titles']);
	}

	/**
	 * @return array{id: string, name: string, title: string, thumbnailUrl: string, resourceUrl: string}
	 */
	public function updateSticker(string $packName, string $stickerId, ?string $title = null): array {
		[$packName, $fileName] = $this->requireSticker($packName, $stickerId);

		$metadata = $this->storage->readMetadata($packName);
		$title = trim((string)$title);
		if ($title !== '') {
			$metadata['titles'][$fileName] = $title;
		} else {
			unset($metadata['titles'][$fileName]);
		}
		$this->storage->writeMetadata($packName, $metadata);

		return $this->buildStickerEntry($packName, $fileName, $metadata['titles']);
	}

	public function deleteSticker(string $packName, string $stickerId): void {
		[$packName, $fileName] = $this->requireSticker($packName, $stickerId);

		$file = $this->storage->getStickerFile($packName, $fileName);
		if ($file === null) {
			throw new \InvalidArgumentException('Sticker not found');
		}
		$file->delete();

		$metadata = $this->storage->readMetadata($packName);
		unset($metadata['titles'][$fileName]);
		$this->storage->writeMetadata($packName, $metadata);
	}

	public function getMimeType(string $fileName): string {
		return match (StorageService::getExtension($fileName)) {
			'png' => 'image/png',
			'gif' => 'image/gif',
			'jpg', 'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			default => 'application/octet-stream',
		};
	}

	public function encodeStickerId(string $packName, string $fileName): string {
		return rtrim(strtr(base64_encode($packName . ':' . $fileName), '+/', '-_'), '=');
	}

	/**
	 * @return array{0: string, 1: string}|null [pack name, file name]
	 */
	public function decodeStickerId(string $stickerId): ?array {
		if ($stickerId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $stickerId) !== 1) {
			return null;
		}

		$padded = str_pad(strtr($stickerId, '-_', '+/'), (int)(ceil(strlen($stickerId) / 4) * 4), '=');
		$decoded = base64_decode($padded, true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return null;
		}

		[$packName, $fileName] = explode(':', $decoded, 2);
		// Reject everything which must not become a storage path.
		if (!StorageService::isValidPackName($packName) || !StorageService::isValidFileName($fileName)) {
			return null;
		}

		return [$packName, $fileName];
	}

	/**
	 * @return array{0: string, 1: string} [pack name, file name]
	 */
	private function requireSticker(string $packName, string $stickerId): array {
		$decoded = $this->decodeStickerId($stickerId);
		if ($decoded === null || $decoded[0] !== $packName) {
			throw new \InvalidArgumentException('Sticker not found');
		}
		if ($this->storage->getStickerFile($packName, $decoded[1]) === null) {
			throw new \InvalidArgumentException('Sticker not found');
		}

		return $decoded;
	}

	/**
	 * @return array{name: string, displayName: string, description: string, stickerCount: int}|null
	 */
	private function buildPackData(string $packName): ?array {
		if ($this->storage->getPackFolder($packName) === null || !$this->storage->hasPackMetadata($packName)) {
			return null;
		}

		$metadata = $this->storage->readMetadata($packName);

		return [
			'name' => $packName,
			'displayName' => $metadata['displayName'],
			'description' => $metadata['description'],
			'stickerCount' => count($this->storage->getStickerFiles($packName)),
		];
	}

	/**
	 * @param array<string, string> $titles
	 * @return array{id: string, name: string, title: string, thumbnailUrl: string, resourceUrl: string}
	 */
	private function buildStickerEntry(string $packName, string $fileName, array $titles): array {
		$id = $this->encodeStickerId($packName, $fileName);
		// Absolute on purpose: the URL is stored inside chat messages and has to
		// stay valid when the message is federated to another instance.
		$url = $this->urlGenerator->linkToRouteAbsolute('r-stiker.sticker.getSticker', ['stickerId' => $id]);

		return [
			'id' => $id,
			'name' => $fileName,
			'title' => $titles[$fileName] ?? pathinfo($fileName, PATHINFO_FILENAME),
			'thumbnailUrl' => $url,
			'resourceUrl' => $url,
		];
	}

	private function buildUniqueFileName(string $packName, string $baseName, string $extension): string {
		$candidate = $baseName . '.' . $extension;
		$suffix = 1;
		while ($this->storage->getStickerFile($packName, $candidate) !== null) {
			$candidate = $baseName . '-' . $suffix . '.' . $extension;
			$suffix++;
		}

		return $candidate;
	}

	private function sanitizeFileName(string $originalName): string {
		$baseName = pathinfo($originalName, PATHINFO_FILENAME);
		$baseName = str_replace(['/', '\\', ':'], '-', $baseName);
		$baseName = preg_replace('/[^\p{L}\p{N} \-_.]+/u', '-', $baseName) ?? '';
		$baseName = preg_replace('/-{2,}/', '-', $baseName) ?? '';
		$baseName = trim($baseName, ' -._');
		if ($baseName === '') {
			$baseName = 'sticker';
		}

		return mb_substr($baseName, 0, 64);
	}

	private function detectMimeType(string $path): ?string {
		$imageInfo = @getimagesize($path);
		$mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
		if (!is_string($mimeType) || !in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
			return null;
		}

		// Cross check with the file content: crafted files are rejected here.
		$detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
		if (is_string($detected) && $detected !== '' && !in_array($detected, self::SUPPORTED_MIME_TYPES, true)) {
			return null;
		}

		return $mimeType;
	}
}
