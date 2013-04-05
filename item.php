<?php

class Item
{
    private $randomUid = false;
    private $prefix = '';
    private $prefixOnlyTitle = true;
    private $title;
    private $comparator;
    private $subtitle;
    private $arg;
    private $valid = true;
    private $add = '…';
    private $autocomplete = true;
    private $prio = 0;
    private $missingChars = 0;
    private $sameChars = 0;

    public static function create()
    {
        return new self;
    }

    public function randomUid()
    {
        $this->randomUid = true;
        return $this;
    }

    public function prefix($prefix, $onlyTitle = true)
    {
        $this->prefix = $prefix;
        $this->prefixOnlyTitle = $onlyTitle;
        return $this;
    }

    public function title($title)
    {
        $this->title = $title;
        return $this;
    }

    public function comparator($comparator)
    {
        $this->comparator = $comparator;
        return $this;
    }

    public function subtitle($subtitle)
    {
        $this->subtitle = $subtitle;
        return $this;
    }

    public function arg($arg)
    {
        $this->arg = $arg;
        return $this;
    }

    public function valid($valid, $add = '…')
    {
        $this->valid = (bool) $valid;
        $this->add = $add;
        return $this;
    }

    public function autocomplete($autocomplete = true)
    {
        $this->autocomplete = (bool) $autocomplete;
        return $this;
    }

    public function prio($prio)
    {
        $this->prio = $prio;
        return $this;
    }

    public function match($query)
    {
        $comparator = strtolower($this->comparator ?: $this->title);
        $query = strtolower($query);
        if (!$this->prefixOnlyTitle && stripos($query, $this->prefix) === 0) {
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
                $this->sameChars++;
            }
        }
        $this->missingChars = strlen($comparator) - $queryLength;
        return true;
    }

    public function compare(self $another)
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
     * @return string
     */
    public static function toXml(array $items)
    {
        $xml = new SimpleXMLElement('<items></items>');
        foreach ($items as $item) {
            $c = $xml->addChild('item');
            $title = $item->prefix . $item->title;
            $c->addAttribute('uid', $item->randomUid ? md5(time() . $title) : md5($title));
            $c->addChild('icon', 'icon.png');
            if ($item->arg) {
                $c->addAttribute('arg', $item->arg);
            }
            if ($item->autocomplete) {
                $c->addAttribute('autocomplete', ' ' . ($item->prefixOnlyTitle ? $item->title : $item->prefix . $item->title));
            }
            if (!$item->valid) {
                $c->addAttribute('valid', 'no');
                $title .= $item->add;
            }
            $c->addChild('title', htmlspecialchars($title));
            if ($item->subtitle) {
                $c->addChild('subtitle', htmlspecialchars($item->subtitle));
            }
        }
        return $xml->asXML();
    }
}
