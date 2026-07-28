<?php

namespace App\Contract;

use App\Entity\CommentThread;
use App\Enum\CommentableType;

interface CommentableContentInterface
{
    public function getId(): ?int;

    public function getSlug(): ?string;

    public function getCommentableTitle(): string;

    public function getCommentableType(): CommentableType;

    public function getCommentThread(): CommentThread;

    public function setCommentThread(CommentThread $commentThread): static;

    public function isPublished(): bool;
}
