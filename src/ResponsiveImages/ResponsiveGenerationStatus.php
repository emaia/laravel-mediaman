<?php

namespace Emaia\MediaMan\ResponsiveImages;

enum ResponsiveGenerationStatus: string
{
    case NoOp = 'no-op';
    case Published = 'published';
    case Partial = 'partial';
}
