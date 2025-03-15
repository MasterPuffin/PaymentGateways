<?php

enum Status: string {
	case Open = 'open';
	case Succeeded = 'succeeded';
	case Pending = 'pending';
	case Failed = 'failed';
}