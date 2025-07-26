-- Create the table for transactions
CREATE TABLE transactions (
    id SERIAL PRIMARY KEY,
    correlation_id VARCHAR(36) NOT NULL,
    amount INT NOT NULL,
    processor VARCHAR(10) NOT NULL,
    success BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Create an index on the timestamp for faster summary queries
CREATE INDEX idx_transactions_created_at ON transactions(created_at);

-- Optional: Create an index on the processor for faster filtering
CREATE INDEX idx_transactions_processor ON transactions(processor);
