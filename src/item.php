<?php

final class Item
{
    private bool $randomUid = false;
    private string $prefix = '';
    private bool $prefixOnlyTitle = true;
    private ?string $title = null;
    private ?string $comparator = null;
    private ?string $subtitle = null;
    private ?string $icon = null;
    private ?string $arg = null;
    private bool $valid = true;
    private string $add = '…';
    private bool|string $autocomplete = true;
    private int $prio = 0;
    private int $missingChars = 0;
    private int $sameChars = 0;

    public static function create(): self
    {
        return new self();
    }

    public function randomUid(): self
    {
        $this->randomUid = true;

        return $this;
    }

    public function prefix(string $prefix, bool $onlyTitle = true): self
    {
        $this->prefix = $prefix;
        $this->prefixOnlyTitle = $onlyTitle;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function comparator(string $comparator): self
    {
        $this->comparator = $comparator;

        return $this;
    }

    public function subtitle(string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function arg(string $arg): self
    {
        $this->arg = $arg;

        return $this;
    }

    public function valid(bool $valid, string $add = '…'): self
    {
        $this->valid = $valid;
        $this->add = $add;

        return $this;
    }

    public function autocomplete(bool|string $autocomplete = true): self
    {
        $this->autocomplete = $autocomplete;

        return $this;
    }

    public function prio(int $prio): self
    {
        $this->prio = $prio;

        return $this;
    }

    public function match(string $query): bool
    {
        $comparator = strtolower($this->comparator ?: $this->title);
        $query = strtolower($query);
        if (!$this->prefixOnlyTitle && 0 === stripos($query, $this->prefix)) {
            $query = substr($query, strlen($this->prefix));
        }
        $this->sameChars = 0;
        $queryLength = strlen($query);
        for ($i = 0, $k = 0; $i < $queryLength; ++$i, $k++) {
            for (; isset($comparator[$k]) && $comparator[$k] !== $query[$i]; ++$k);

            if (!isset($comparator[$k])) {
                return false;
            }
            if ($i === $k) {
                ++$this->sameChars;
            }
        }
        $this->missingChars = strlen($comparator) - $queryLength;

        return true;
    }

    public function compare(self $another): int
    {
        if ($this->sameChars != $another->sameChars) {
            return $this->sameChars < $another->sameChars ? 1 : -1;
        }
        if ($this->prio != $another->prio) {
            return $this->prio < $another->prio ? 1 : -1;
        }

        return $this->missingChars > $another->missingChars ? 1 : -1;
    }

    /**
     * @param self[] $items
     *
     * @return string
     */
    public static function toXml(array $items, bool $enterprise, bool|string $hotkey, ?string $baseUrl): string|false
    {
        $xml = new SimpleXMLElement('<items></items>');
        $prefix = $hotkey ? '' : ' ';
        foreach ($items as $item) {
            $c = $xml->addChild('item');
            $title = $item->prefix . $item->title;
            $c->addAttribute('uid', $item->randomUid ? md5(time() . $title) : md5($title));
            if ($item->icon && file_exists(__DIR__ . '/icons/' . $item->icon . '.png')) {
                $c->addChild('icon', 'icons/' . $item->icon . '.png');
            } else {
                $c->addChild('icon', 'icon.png');
            }
            if ($item->arg) {
                $arg = $item->arg;
                if ('/' === $arg[0]) {
                    $arg = $baseUrl . $arg;
                } elseif (!str_contains($arg, '://')) {
                    $arg = ($enterprise ? 'e ' : '') . $arg;
                }
                $c->addAttribute('arg', $arg);
            }
            if ($item->autocomplete) {
                if (is_string($item->autocomplete)) {
                    $autocomplete = $item->autocomplete;
                } elseif (null !== $item->comparator) {
                    $autocomplete = $item->comparator;
                } else {
                    $autocomplete = $item->title;
                }
                $c->addAttribute('autocomplete', $prefix . ($item->prefixOnlyTitle ? $autocomplete : $item->prefix . $autocomplete));
            }
            if (!$item->valid) {
                $c->addAttribute('valid', 'no');
                $title .= $item->add;
            }
            $flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_XML1;
            $c->addChild('title', htmlspecialchars($title, $flags));
            if ($item->subtitle) {
                $c->addChild('subtitle', htmlspecialchars($item->subtitle, $flags));
            }
        }

        return $xml->asXML();
    }
}
